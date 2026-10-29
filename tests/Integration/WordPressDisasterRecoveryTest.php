<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use ThreadSafe\Backup\Manifest;
use ThreadSafe\Build\RestoreBuilder;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Tests\Support\CliProcess;
use ThreadSafe\Tests\Support\Stack;
use ThreadSafe\Tests\Support\WebWizard;
use ThreadSafe\Tests\Support\WizardJourney;
use ThreadSafe\Tests\Support\WordPressClient;

/**
 * Disaster recovery of a WordPress site (docs/PHASE9.md 4.2 item 4; the PrestaShop one is DisasterRecoveryTest): a seeded site is restored onto
 * the target (the working site), and the working site is backed up. Then `tests/stack/run.sh` DESTROYS the target's containers and volumes and
 * makes them again, empty, on the same domain and port. The single file `threadsafe-restore.php` goes into the empty web directory with the key
 * and the backup, and the wizard - served by the target's own Apache under limits of 5 seconds and 64 MB - brings the site back **on the same
 * address**. Then the pages are asked for, the administrator logs in, and every table and file is compared with what the site was before.
 *
 * Two tests in two groups, because a shell script has to destroy the stack between them.
 */
final class WordPressDisasterRecoveryTest extends IntegrationTestCase
{
    private const string DIRECTORY = '/app/.cache/wordpress-disaster';
    private const string TARGET = '/shops/target';
    private const string TARGET_URL = 'http://target-wordpress';
    private const string TARGET_HOST = 'localhost:8291';
    private const string DATABASE = 'wp_target';
    private const string PASSWORD = 'Seed-admin-0123456789';
    private const string KEY = 'wordpress disaster recovery key 12345';

    private static function password(): string
    {
        return getenv('DB_ROOT_PASSWORD') ?: 'prestashop';
    }

    private static function targetDb(): PDO
    {
        return new PDO('mysql:host=target-db;dbname=' . self::DATABASE . ';charset=utf8mb4', 'root', self::password(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    #[Group('wordpress-disaster-prepare')]
    public function testTheSiteIsBackedUpAndDescribed(): void
    {
        if (!is_file('/wordpress7/wp-includes/version.php') || !is_dir(self::TARGET)) {
            self::markTestSkipped('The WordPress site is not mounted (tests/stack/seed-wordpress.sh) or the target is missing.');
        }
        exec('rm -rf ' . escapeshellarg(self::DIRECTORY));
        mkdir(self::DIRECTORY . '/upload', 0777, true);

        // a working site on the target: the seeded WordPress 7, restored on the address that it will have again
        $this->restoreTheWorkingSite();
        $db = self::targetDb();

        // what the site looks like now: the pages
        $client = new WordPressClient(self::TARGET_URL, self::TARGET_HOST, '');
        $pages = [];
        foreach (['/', '/about-us/', '/hello-seed/', '/wp-login.php', '/wp-content/uploads/seed/photo.png', '/?rest_route=/'] as $path) {
            $answer = $client->get($path);
            self::assertSame(200, $answer['status'], "$path answers before the disaster");
            $pages[$path] = md5($answer['body']);
        }
        $admin = new WordPressClient(self::TARGET_URL, self::TARGET_HOST, '');
        self::assertTrue($admin->login('seedadmin', self::PASSWORD));

        // the backup of the working site (the profile reads the database settings from its wp-config.php)
        $data = self::DIRECTORY . '/data';
        (new ProfileStore($data . '/profiles'))->save(SiteProfile::fromArray([
            'id' => 'site', 'name' => 'Working WordPress', 'type' => 'wordpress', 'path' => self::TARGET, 'storage' => self::DIRECTORY . '/upload',
        ]));
        putenv('THREADSAFE_DATA=' . $data);
        try {
            [$code, $stdout, $stderr] = CliProcess::run(['backup', 'site', '--part-size=20M'], 1800);
            self::assertSame(0, $code, $stdout . $stderr);
        } finally {
            putenv('THREADSAFE_DATA');
        }
        $parts = glob(self::DIRECTORY . '/upload/site_*-p*.zip') ?: [];
        self::assertGreaterThan(1, count($parts), 'the backup of the site has several parts');
        $manifest = Manifest::fromArchive((string) end($parts));

        // what the tool and the person put into the empty directory
        (new RestoreBuilder(dirname(__DIR__, 2)))->build(self::DIRECTORY . '/upload/threadsafe-restore.php');
        file_put_contents(self::DIRECTORY . '/upload/threadsafe-restore-key.php', "<?php return '" . self::KEY . "';");

        // the state of the site as the backup holds it (nothing was asked of the site since the backup)
        $structureOnly = array_map(static fn (string $t): string => 'wp_' . $t, $manifest->filters['structureOnlyTables']);
        $tables = [];
        foreach ($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . self::DATABASE . "' AND TABLE_TYPE = 'BASE TABLE' ORDER BY 1")?->fetchAll(PDO::FETCH_COLUMN) ?: [] as $table) {
            $tables[(string) $table] = in_array($table, $structureOnly, true) ? null : self::fingerprint($db, (string) $table);
        }
        // what a backup leaves out, the tool and the host's placeholder are not the site's
        $skip = ['wp-content/cache', 'wp-content/upgrade', 'wp-content/wflogs', 'wp-content/updraft', 'wp-content/debug.log', 'threadsafe-restore.php', 'threadsafe-restore-key.php', '.threadsafe-restore', 'index.html', 'threadsafe-journal'];
        file_put_contents(self::DIRECTORY . '/expected.json', json_encode([
            'skip' => $skip,
            'pages' => $pages,
            'tables' => $tables,
            'files' => self::files(self::TARGET, $skip),
            'home' => (string) $db->query("SELECT option_value FROM wp_options WHERE option_name = 'home'")?->fetchColumn(),
        ], JSON_THROW_ON_ERROR));
        self::assertGreaterThan(10, count($tables));

        // a mark that only the old volumes can have: written after the backup, so it is not in it
        file_put_contents(self::TARGET . '/.disaster-marker', 'this volume is the old one');
        $db->exec('CREATE TABLE IF NOT EXISTS zz_disaster_marker (id INT)');
    }

    #[Group('wordpress-disaster-restore')]
    public function testTheSiteComesBackOnAnEmptyStackThroughTheWizard(): void
    {
        if (!is_file(self::DIRECTORY . '/expected.json') || !is_dir(self::TARGET)) {
            self::markTestSkipped('The disaster was not prepared (tests/stack/run.sh does it).');
        }
        $expected = json_decode((string) file_get_contents(self::DIRECTORY . '/expected.json'), true, 512, JSON_THROW_ON_ERROR);
        $admin = new PDO('mysql:host=target-db;charset=utf8mb4', 'root', self::password(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        // the stack is new: the marks of the old volumes are gone, the database server has no database of the site, there is no site
        self::assertFileDoesNotExist(self::TARGET . '/.disaster-marker', 'the web directory is a new volume');
        self::assertSame('0', (string) $admin->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '" . self::DATABASE . "'")?->fetchColumn(), 'the database server is new');
        self::assertNotSame(200, Stack::get(self::TARGET_URL, self::TARGET_HOST, '/')->status, 'the site is gone');
        // (the image of the web server puts WordPress's own files into a new volume; a host hands over an empty directory, and a database)
        exec('find ' . escapeshellarg(self::TARGET) . ' -mindepth 1 -delete 2>&1', $ignored, $code);
        self::assertSame(0, $code);
        $admin->exec('CREATE DATABASE `' . self::DATABASE . '` CHARACTER SET utf8mb4');

        // the person uploads three things into the empty directory: the tool, the key, the backup - and what the host had there stays
        exec('chown www-data:www-data ' . escapeshellarg(self::TARGET) . ' 2>&1', $ignored, $code);
        self::assertSame(0, $code);
        foreach (glob(self::DIRECTORY . '/upload/*') ?: [] as $file) {
            copy($file, self::TARGET . '/' . basename($file));
            chmod(self::TARGET . '/' . basename($file), 0644);
        }
        file_put_contents(self::TARGET . '/index.html', '<html>The host says: coming soon</html>');

        $wizard = new WebWizard(baseUrl: self::TARGET_URL, scriptPath: '/threadsafe-restore.php');
        $wrong = $wizard->request('POST', '/', ['action' => 'login', 'key' => 'a stranger guessing']);
        self::assertSame(403, $wrong['status']);

        // the same address as before: the old domain and the new one are the same, and the wizard proposes it
        $journey = WizardJourney::restore($wizard, self::KEY, [
            'root' => '/var/www/html', 'db_host' => 'target-db', 'db_port' => '3306', 'db_name' => self::DATABASE, 'db_user' => 'root', 'db_password' => self::password(), 'db_prefix' => 'wp_',
            'f_from' => '', 'f_to' => self::TARGET_HOST, 'f_path' => '', 'f_mode' => 'production', 'f_keepDropIns' => 'off', 'allow_leftovers' => '1',
        ]);
        self::assertGreaterThan(3, $journey['ticks'], 'a restore of 3,000 files and more in requests of 5 seconds takes several of them');
        $gone = $wizard->request('POST', '/', ['action' => 'cleanup', 'csrf' => $wizard->csrf()]);
        self::assertStringContainsString('The tool is gone', $gone['body']);
        foreach (['threadsafe-restore.php', 'threadsafe-restore-key.php', '.threadsafe-restore'] as $name) {
            self::assertFileDoesNotExist(self::TARGET . '/' . $name, "$name is deleted");
        }
        self::assertFileExists(self::TARGET . '/index.html', 'what the host had there is not touched');

        // every table has what it had (the options table is compared without what WordPress writes by itself: the transients, the rewrite rules, the cron list)
        $db = self::targetDb();
        $different = [];
        foreach ($expected['tables'] as $table => $fingerprint) {
            if ($fingerprint !== null && self::fingerprint($db, $table) !== $fingerprint) {
                $different[] = $table;
            }
        }
        self::assertSame([], $different, 'tables that are not what they were');
        self::assertSame($expected['home'], (string) $db->query("SELECT option_value FROM wp_options WHERE option_name = 'home'")?->fetchColumn());

        // what the person uploaded stays next to the site: the parts of the backup and the protection file of the storage directory
        $files = array_filter(self::files(self::TARGET, $expected['skip']), static fn (string $hash, string $name): bool => preg_match('/^site_[0-9]{8}-[0-9]{6}_[0-9a-f]{8}(-p[0-9]+)?\.(zip|json)$/', $name) !== 1 && $name !== 'web.config', ARRAY_FILTER_USE_BOTH);
        $changed = array_values(array_unique([...array_keys(array_diff_assoc($expected['files'], $files)), ...array_keys(array_diff_key($files, $expected['files']))]));
        self::assertSame([], array_slice($changed, 0, 20), 'files that are not what they were (' . count($changed) . ' in all)');
        self::assertSame('www-data', posix_getpwuid((int) fileowner(self::TARGET . '/index.php'))['name'] ?? '', 'the files belong to the web server, which restored them');

        // the site serves: the same pages with the same answers, on the same address, and the administrator logs in
        $client = new WordPressClient(self::TARGET_URL, self::TARGET_HOST, '');
        foreach ($expected['pages'] as $path => $hash) {
            $answer = $client->get($path);
            self::assertSame(200, $answer['status'], "$path answers again");
            self::assertSame($hash, md5($answer['body']), "$path is what it was");
        }
        $administrator = new WordPressClient(self::TARGET_URL, self::TARGET_HOST, '');
        self::assertTrue($administrator->login('seedadmin', self::PASSWORD), 'the administrator logs in with the password of before');
        self::assertStringContainsString('General Settings', $administrator->get('/wp-admin/options-general.php')['body']);
    }

    /** The seeded WordPress 7 on the target, by the command, under the address that the restored site keeps. */
    private function restoreTheWorkingSite(): void
    {
        exec('find ' . escapeshellarg(self::TARGET) . ' -mindepth 1 -delete 2>&1', $ignored, $code);
        self::assertSame(0, $code, 'The target volume can be emptied.');
        exec('chown www-data:www-data ' . escapeshellarg(self::TARGET) . ' 2>&1', $ignored, $code);
        self::assertSame(0, $code);
        $admin = new PDO('mysql:host=target-db;charset=utf8mb4', 'root', self::password(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $admin->exec('DROP DATABASE IF EXISTS `' . self::DATABASE . '`');
        $admin->exec('CREATE DATABASE `' . self::DATABASE . '` CHARACTER SET utf8mb4');

        $base = self::DIRECTORY . '/first';
        mkdir($base . '/data/profiles', 0777, true);
        mkdir($base . '/storage', 0777, true);
        (new ProfileStore($base . '/data/profiles'))->save(SiteProfile::fromArray([
            'id' => 'seed', 'name' => 'Seeded WordPress 7', 'type' => 'wordpress', 'path' => '/wordpress7', 'storage' => $base . '/storage',
        ]));
        file_put_contents($base . '/db-password', self::password() . "\n");
        putenv('THREADSAFE_DATA=' . $base . '/data');
        try {
            [$code, $stdout, $stderr] = CliProcess::run(['backup', 'seed'], 1800);
            self::assertSame(0, $code, $stdout . $stderr);
            $archives = glob($base . '/storage/seed_*.zip') ?: [];
            self::assertCount(1, $archives);
            [$code, $stdout, $stderr] = CliProcess::run([
                'restore', $archives[0], '--root=' . self::TARGET, '--to=' . self::TARGET_HOST,
                '--db-host=target-db', '--db-name=' . self::DATABASE, '--db-user=root', '--db-password-file=' . $base . '/db-password',
                '--server-path=/var/www/html', '--self-test-url=' . self::TARGET_URL, '--yes',
            ], 1800);
            self::assertSame(0, $code, $stderr . $stdout);
        } finally {
            putenv('THREADSAFE_DATA');
        }
        exec('rm -rf ' . escapeshellarg($base));
    }

    /**
     * What a table holds, as a number that does not depend on the server's collation or on the order of the rows. The options table leaves out
     * what WordPress writes by itself while it is asked for pages (transients, the cron list) and what the restore makes WordPress build again
     * (the rewrite rules).
     */
    private static function fingerprint(PDO $db, string $table): string
    {
        $columns = [];
        foreach ($db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '" . self::DATABASE . "' AND TABLE_NAME = '$table' ORDER BY ORDINAL_POSITION")?->fetchAll(PDO::FETCH_COLUMN) ?: [] as $column) {
            $columns[] = "IFNULL(HEX(`$column`), 'NULL')";
        }
        $where = $table === 'wp_options'
            ? " WHERE option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%' AND option_name NOT IN ('rewrite_rules', 'cron')"
            : '';
        $row = $db->query('SELECT COUNT(*), SUM(CRC32(CONCAT_WS(\'|\', ' . implode(', ', $columns) . ")))  FROM `$table`$where")?->fetch(PDO::FETCH_NUM);

        return is_array($row) ? $row[0] . ':' . $row[1] : '';
    }

    /**
     * @param list<string> $skip paths (and everything below them) that are left out
     *
     * @return array<string, string> relative path => MD5 of the content
     */
    private static function files(string $root, array $skip): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $path => $info) {
            $relative = substr((string) $path, strlen($root) + 1);
            foreach ($skip as $prefix) {
                if ($relative === $prefix || str_starts_with($relative, $prefix . '/')) {
                    continue 2;
                }
            }
            if ($info->isFile() && $info->getFilename() !== 'error_log') {
                $files[$relative] = (string) md5_file((string) $path);
            }
        }
        ksort($files);

        return $files;
    }
}
