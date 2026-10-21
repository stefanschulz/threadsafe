<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Backup\Manifest;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Tests\Support\CliProcess;
use ThreadSafe\Tests\Support\Stack;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * Milestone A of phase 3: `threadsafe restore` does in one command what BackupRoundTripTest does with `unzip`, the `mariadb` client
 * and `migrate`. The seeded source shop (299 tables, 45,000 files) is backed up through its profile, the target stack is emptied,
 * and the backup goes into it - files, database, new domain, self-test over HTTP - with nothing but the archive and the database
 * password. Then every table and file is compared with the source.
 *
 * Runs in the last phase of tests/stack/run.sh with the backup round trip: it wipes the target stack.
 */
#[Group('backup-roundtrip')]
final class RestoreCommandShopTest extends IntegrationTestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    public function testTheBackupOfTheShopIsRestoredWithOneCommand(): void
    {
        if (!is_dir('/shops/source/src/Core') || !is_dir('/shops/target')) {
            self::markTestSkipped('The shop volumes are not mounted at /shops/source and /shops/target.');
        }
        $rootPassword = getenv('DB_ROOT_PASSWORD') ?: 'prestashop';
        $base = $this->tempDirectory();
        $data = $base . '/data';
        (new ProfileStore($data . '/profiles'))->save(SiteProfile::fromArray([
            'id' => 'shop', 'name' => 'Seeded source shop', 'type' => 'prestashop9', 'path' => '/shops/source', 'storage' => $base . '/storage',
        ]));

        // --- 1. the backup
        putenv('THREADSAFE_DATA=' . $data);
        try {
            [$code, $stdout, $stderr] = CliProcess::run(['backup', 'shop'], 900);
            self::assertSame(0, $code, $stdout . $stderr);
            $archives = glob($base . '/storage/shop_*.zip') ?: [];
            self::assertCount(1, $archives);
            $archive = $archives[0];
            $manifest = Manifest::fromArchive($archive);

            // --- 2. an empty target: an empty directory owned by the web server, an empty database
            exec('find /shops/target -mindepth 1 -delete 2>&1', $ignored, $code);
            self::assertSame(0, $code, 'The target volume can be emptied.');
            exec('chown www-data:www-data /shops/target 2>&1', $ignored, $code);
            self::assertSame(0, $code);
            $admin = new PDO('mysql:host=target-db;charset=utf8mb4', 'root', $rootPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $admin->exec('DROP DATABASE IF EXISTS prestashop_target');
            $admin->exec('CREATE DATABASE prestashop_target CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');

            // --- 3. one command
            file_put_contents($base . '/db-password', "target\n");
            $command = [
                'restore', $archive, '--root=/shops/target', '--to=' . Stack::TARGET_HOST,
                '--db-host=target-db', '--db-name=prestashop_target', '--db-user=target', '--db-password-file=' . $base . '/db-password',
                '--server-path=/var/www/html',
                '--rules-dir=' . dirname(__DIR__) . '/fixtures/rules',
                '--self-test-url=' . Stack::TARGET_URL,
                '--report=' . $base . '/result.json',
            ];
            [$code, $stdout, $stderr] = CliProcess::run($command, 900);
            self::assertSame(0, $code, 'Without --yes it only inspects: ' . $stderr . $stdout);
            self::assertStringContainsString('Nothing was changed.', $stdout);
            self::assertSame('0', self::cell(Stack::targetDb(), "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'prestashop_target'"));

            [$code, $stdout, $stderr] = CliProcess::run([...$command, '--yes'], 1800);
            self::assertSame(0, $code, $stderr . $stdout);
            self::assertStringContainsString('Restored ' . $manifest->backupId, $stdout);
            self::assertStringContainsString('Run finished.', $stdout);
        } finally {
            putenv('THREADSAFE_DATA');
        }

        // --- 4. the state that held the password is gone, the unpacked SQL too
        self::assertSame([], glob($data . '/runs/*.json') ?: [], 'the run state is deleted');
        self::assertDirectoryDoesNotExist('/shops/target/.threadsafe-restore/db');
        $result = json_decode((string) file_get_contents($base . '/result.json'), true);
        self::assertSame($manifest->counts['files'], $result['files']);
        self::assertDoesNotMatchRegularExpression('/"password"\s*:/', (string) file_get_contents($base . '/result.json'), 'the result holds no database settings');

        // --- 5. every table and every file equals the source; the shop answers on the new domain
        $source = Stack::sourceDb();
        $target = Stack::targetDb();
        $structureOnly = array_map(static fn (string $t): string => 'ps_' . $t, $manifest->filters['structureOnlyTables']);
        $listing = $source->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'prestashop' AND TABLE_TYPE = 'BASE TABLE' ORDER BY 1");
        self::assertNotFalse($listing);
        $tables = $listing->fetchAll(PDO::FETCH_COLUMN);
        $sameKind = str_contains((string) self::cell($source, 'SELECT VERSION()'), 'MariaDB') === str_contains((string) self::cell($target, 'SELECT VERSION()'), 'MariaDB');
        $different = [];
        foreach ($tables as $table) {
            if (in_array($table, $structureOnly, true)) {
                // the self-test visits pages, and the shop records the visits: a few rows, not the 200,000 of the source
                self::assertLessThan(50, (int) self::cell($target, "SELECT COUNT(*) FROM `$table`"), "$table keeps only its structure");
                continue;
            }
            if (in_array($table, ['ps_page', 'ps_page_type'], true)) {
                continue;   // statistics of the pages the self-test visited
            }
            if (self::mentions($source, $table, Stack::SOURCE_HOST)) {
                // the new domain changed text in it: all rows must be there, the cells are checked by the migration tests
                self::assertSame(self::cell($source, "SELECT COUNT(*) FROM `$table`"), self::cell($target, "SELECT COUNT(*) FROM `$table`"), "$table has all rows");
                continue;
            }
            if (self::fingerprint($source, $table, $sameKind) !== self::fingerprint($target, $table, $sameKind)) {
                $different[] = $table;
            }
        }
        self::assertSame([], $different, 'Every table in which the old domain does not occur has the checksum of the source.');

        $files = 0;
        $rewritten = 0;
        foreach (ZipReader::open($archive)->entries() as $entry) {
            if (!str_starts_with($entry->name, 'files/') || $entry->isDirectory) {
                continue;
            }
            $relative = substr($entry->name, 6);
            ++$files;
            if (in_array($relative, ['app/config/parameters.php', 'robots.txt', '.htaccess', 'php.ini'], true) || str_starts_with($relative, 'var/')) {
                continue;   // rewritten for the new database and domain, or made by the shop itself
            }
            if (md5_file('/shops/source/' . $relative) !== md5_file('/shops/target/' . $relative)) {
                // only a file that names the old domain may differ (sitemaps, generated files the migration fixes)
                self::assertTrue(str_contains((string) file_get_contents('/shops/source/' . $relative), Stack::SOURCE_HOST), "The file /$relative differs from the source.");
                ++$rewritten;
            }
        }
        self::assertLessThan(20, $rewritten, 'Only a few files name the old domain.');
        self::assertSame($manifest->counts['files'], $files);
        self::assertSame((int) fileowner('/shops/target'), (int) fileowner('/shops/target/index.php'), 'files belong to the owner of the target directory');

        $home = Stack::get(Stack::TARGET_URL, Stack::TARGET_HOST, '/');
        self::assertSame(200, $home->status);
        self::assertSame(Stack::TARGET_HOST, self::cell($target, 'SELECT domain FROM ps_shop_url LIMIT 1'));
        self::assertSame(Stack::SOURCE_HOST, self::cell($source, 'SELECT domain FROM ps_shop_url LIMIT 1'), 'The source was not touched.');
    }

    /** Whether a text column of the table mentions the host name (the new domain changes those tables). */
    private static function mentions(PDO $db, string $table, string $host): bool
    {
        $statement = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND DATA_TYPE IN ('char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json')");
        self::assertNotFalse($statement);
        $columns = $statement->fetchAll(PDO::FETCH_COLUMN);
        if ($columns === []) {
            return false;
        }
        $conditions = implode(' OR ', array_map(static fn (string $c): string => "`$c` LIKE ?", $columns));
        $count = $db->prepare("SELECT COUNT(*) FROM `$table` WHERE $conditions");
        $count->execute(array_fill(0, count($columns), '%' . $host . '%'));

        return (int) $count->fetchColumn() > 0;
    }

    private static function fingerprint(PDO $db, string $table, bool $checksum): string
    {
        if ($checksum) {
            return self::cell($db, "CHECKSUM TABLE `$table`", 1);
        }
        $statement = $db->query("SELECT * FROM `$table`");
        self::assertNotFalse($statement);
        $sum = 0;
        $rows = 0;
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $sum = ($sum + crc32(serialize($row))) & 0xFFFFFFFF;
            ++$rows;
        }

        return $rows . ':' . $sum;
    }

    /** The first (or $column-th) column of the first row of a query. */
    private static function cell(PDO $db, string $sql, int $column = 0): string
    {
        $statement = $db->query($sql);
        self::assertNotFalse($statement);
        $row = $statement->fetch(PDO::FETCH_NUM);

        return is_array($row) ? (string) $row[$column] : '';
    }
}
