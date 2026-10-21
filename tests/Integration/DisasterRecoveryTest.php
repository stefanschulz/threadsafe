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
use ThreadSafe\Tests\Support\ShopSmoke;
use ThreadSafe\Tests\Support\Stack;
use ThreadSafe\Tests\Support\WebWizard;
use ThreadSafe\Tests\Support\WizardJourney;

/**
 * Disaster recovery (docs/CONCEPT.md section 7, PHASE3.md 4.2 item 3): the working target shop is backed up; then
 * `tests/stack/run.sh` DESTROYS the target's containers and volumes and makes them again, empty, on the same domain and port; the
 * single file `restore.php` goes into the empty web directory with the key and the backup, and the wizard - served by the target's
 * own Apache, under the limits of `.docker/target-restore-limits.conf` (5 seconds, 64 MB) - brings the shop back. Then the pages are
 * asked for, and every table and file is compared with what the shop was before.
 *
 * Two tests in two groups, because a shell script has to destroy the stack between them.
 */
final class DisasterRecoveryTest extends IntegrationTestCase
{
    private const string DIRECTORY = '/app/.cache/disaster';

    #[Group('disaster-prepare')]
    public function testTheShopIsBackedUpAndDescribed(): void
    {
        if (!is_dir('/shops/target/src/Core')) {
            self::markTestSkipped('The target shop is not mounted at /shops/target or is not there.');
        }
        exec('rm -rf ' . escapeshellarg(self::DIRECTORY));
        mkdir(self::DIRECTORY . '/upload', 0777, true);
        $db = Stack::targetDb();

        // what the shop looks like now: the pages, every table, every file
        $paths = ShopSmoke::paths($db);
        $pages = ShopSmoke::visit(Stack::TARGET_URL, Stack::TARGET_HOST, $paths);
        foreach ($pages as $name => $page) {
            self::assertSame(200, $page['status'], "$name answers before the disaster");
        }

        // the backup of the working shop (the profile reads the database settings from the shop's parameters.php)
        $data = self::DIRECTORY . '/data';
        (new ProfileStore($data . '/profiles'))->save(SiteProfile::fromArray([
            'id' => 'shop', 'type' => 'prestashop9', 'path' => '/shops/target', 'storage' => self::DIRECTORY . '/upload',
        ]));
        putenv('THREADSAFE_DATA=' . $data);
        try {
            [$code, $stdout, $stderr] = CliProcess::run(['backup', 'shop', '--part-size=100M'], 1800);
            self::assertSame(0, $code, $stdout . $stderr);
        } finally {
            putenv('THREADSAFE_DATA');
        }
        $parts = glob(self::DIRECTORY . '/upload/shop_*-p*.zip') ?: [];
        self::assertGreaterThan(1, count($parts), 'the backup of the shop has several parts');
        $manifest = Manifest::fromArchive((string) end($parts));

        // what the tool and the person put into the empty directory
        (new RestoreBuilder(dirname(__DIR__, 2)))->build(self::DIRECTORY . '/upload/restore.php');
        file_put_contents(self::DIRECTORY . '/upload/restore-key.php', "<?php return 'disaster recovery key 12345';");

        // the state of the shop as the backup holds it (structure-only tables keep no rows; the visits above wrote some)
        $structureOnly = array_map(static fn (string $t): string => 'ps_' . $t, $manifest->filters['structureOnlyTables']);
        $checksums = [];
        foreach ($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'prestashop_target' AND TABLE_TYPE = 'BASE TABLE' ORDER BY 1")?->fetchAll(PDO::FETCH_COLUMN) ?: [] as $table) {
            $row = in_array($table, $structureOnly, true) ? null : $db->query("CHECKSUM TABLE `$table`")->fetch(PDO::FETCH_NUM);
            $checksums[(string) $table] = is_array($row) ? (string) $row[1] : null;
        }
        // the backup leaves some paths out (caches, logs); the tool and the host's placeholder are not the shop's
        $skip = [...$manifest->filters['excludePaths'], ...$manifest->filters['literalExcludes'], 'restore.php', 'restore-key.php', '.threadsafe-restore', 'index.html', 'threadsafe-journal'];
        file_put_contents(self::DIRECTORY . '/expected.json', json_encode([
            'skip' => $skip,
            'paths' => $paths,
            'pages' => $pages,
            'checksums' => $checksums,
            'files' => self::files('/shops/target', $skip),
            'domain' => (string) $db->query('SELECT domain FROM ps_shop_url ORDER BY id_shop_url LIMIT 1')?->fetchColumn(),
        ], JSON_THROW_ON_ERROR));
        self::assertGreaterThan(250, count($checksums));

        // a mark that only the old volumes can have: written after the backup, so it is not in it
        file_put_contents('/shops/target/.disaster-marker', 'this volume is the old one');
        $db->exec('CREATE TABLE IF NOT EXISTS zz_disaster_marker (id INT)');
    }

    #[Group('disaster-restore')]
    public function testTheShopComesBackOnAnEmptyStackThroughTheWizard(): void
    {
        if (!is_file(self::DIRECTORY . '/expected.json') || !is_dir('/shops/target')) {
            self::markTestSkipped('The disaster was not prepared (tests/stack/run.sh does it).');
        }
        $expected = json_decode((string) file_get_contents(self::DIRECTORY . '/expected.json'), true, 512, JSON_THROW_ON_ERROR);
        $db = Stack::targetDb();

        // the stack is new: the marks of the old volumes are gone, the database has no tables, there is no shop
        self::assertFileDoesNotExist('/shops/target/.disaster-marker', 'the web directory is a new volume');
        self::assertSame('0', (string) $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'prestashop_target'")?->fetchColumn(), 'the database is new');
        self::assertNotSame(200, Stack::get(Stack::TARGET_URL, Stack::TARGET_HOST, '/')->status, 'the shop is gone');
        // (the image of the web server puts PrestaShop's own files into a new volume; a host hands over an empty directory)
        exec('find /shops/target -mindepth 1 -delete 2>&1', $ignored, $code);
        self::assertSame(0, $code);

        // the person uploads three things into the empty directory: the tool, the key, the backup - and what the host had there stays
        exec('chown www-data:www-data /shops/target 2>&1', $ignored, $code);
        self::assertSame(0, $code);
        foreach (glob(self::DIRECTORY . '/upload/*') ?: [] as $file) {
            copy($file, '/shops/target/' . basename($file));
            chmod('/shops/target/' . basename($file), 0644);
        }
        file_put_contents('/shops/target/index.html', '<html>The host says: coming soon</html>');

        $wizard = new WebWizard(baseUrl: Stack::TARGET_URL, scriptPath: '/restore.php');
        $wrong = $wizard->request('POST', '/', ['action' => 'login', 'key' => 'a stranger guessing']);
        self::assertSame(403, $wrong['status']);

        $journey = WizardJourney::restore($wizard, 'disaster recovery key 12345', [
            'root' => '/var/www/html', 'db_host' => 'target-db', 'db_port' => '3306', 'db_name' => 'prestashop_target', 'db_user' => 'target', 'db_password' => 'target', 'db_prefix' => 'ps_',
            'f_to' => Stack::TARGET_HOST, 'f_path' => '', 'f_ssl' => '', 'f_mode' => 'production', 'f_mailCatcher' => '', 'allow_leftovers' => '1',
        ]);
        self::assertGreaterThan(3, $journey['ticks'], 'a restore of 40,000 files in requests of 5 seconds takes several of them');
        $gone = $wizard->request('POST', '/', ['action' => 'cleanup', 'csrf' => $wizard->csrf()]);
        self::assertStringContainsString('The tool is gone', $gone['body']);
        foreach (['restore.php', 'restore-key.php', '.threadsafe-restore'] as $name) {
            self::assertFileDoesNotExist('/shops/target/' . $name, "$name is deleted");
        }
        self::assertFileExists('/shops/target/index.html', 'what the host had there is not touched');

        // the shop serves: the same pages with the same answers
        $pages = ShopSmoke::visit(Stack::TARGET_URL, Stack::TARGET_HOST, $expected['paths']);
        self::assertSame($expected['pages'], $pages, 'home page, category, product, product image, contact and back office login answer as before');
        $home = Stack::get(Stack::TARGET_URL, Stack::TARGET_HOST, '/');
        self::assertStringNotContainsString(Stack::SOURCE_HOST, $home->body);
        self::assertSame($expected['domain'], (string) $db->query('SELECT domain FROM ps_shop_url ORDER BY id_shop_url LIMIT 1')?->fetchColumn());

        // every table has the checksum it had, every file the content it had
        $different = [];
        foreach ($expected['checksums'] as $table => $checksum) {
            if ($checksum === null) {
                continue;
            }
            $now = $db->query("CHECKSUM TABLE `$table`")?->fetch(PDO::FETCH_NUM);
            if (!is_array($now) || (string) $now[1] !== $checksum) {
                $different[] = $table;
            }
        }
        self::assertSame([], $different, 'tables that are not what they were');
        // what the person uploaded stays next to the shop: the parts of the backup and the protection file of the storage directory
        $files = array_filter(self::files('/shops/target', $expected['skip']), static fn (string $hash, string $name): bool => preg_match('/^shop_[0-9]{8}-[0-9]{6}_[0-9a-f]{8}(-p[0-9]+)?\.(zip|json)$/', $name) !== 1 && $name !== 'web.config', ARRAY_FILTER_USE_BOTH);
        $changed = array_values(array_unique([...array_keys(array_diff_assoc($expected['files'], $files)), ...array_keys(array_diff_key($files, $expected['files']))]));
        self::assertSame([], array_slice($changed, 0, 20), 'files that are not what they were (' . count($changed) . ' in all)');
        self::assertSame('www-data', posix_getpwuid((int) fileowner('/shops/target/index.php'))['name'] ?? '', 'the files belong to the web server, which restored them');
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
            if ($info->isFile()) {
                $files[$relative] = (string) md5_file((string) $path);
            }
        }
        ksort($files);

        return $files;
    }
}
