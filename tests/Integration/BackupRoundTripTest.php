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
use ThreadSafe\Tests\Support\SqlClient;
use ThreadSafe\Tests\Support\Stack;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Tests\Support\UnzipOracle;

/**
 * The main test of phase 2, with no restore wizard: the seeded source shop (299 tables, 45,000 files) is backed up through its
 * profile, the archive is taken apart with `unzip`, the SQL files are imported with the plain `mariadb` client into the
 * isolated target stack, every table and file is compared with the source, and `threadsafe migrate prestashop9` turns the copy
 * into a working shop on the target domain (its self-test requests the pages over HTTP).
 *
 * Runs last (own phase of tests/stack/run.sh): it wipes the target stack, which the earlier phases use.
 */
#[Group('backup-roundtrip')]
final class BackupRoundTripTest extends IntegrationTestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    public function testBackupUnzipImportAndMigrateGiveAWorkingShop(): void
    {
        if (!is_dir('/shops/source/src/Core') || !is_dir('/shops/target')) {
            self::markTestSkipped('The shop volumes are not mounted at /shops/source and /shops/target.');
        }
        if (!UnzipOracle::available()) {
            self::markTestSkipped('unzip is needed');
        }
        $rootPassword = getenv('DB_ROOT_PASSWORD') ?: 'prestashop';
        $base = $this->tempDirectory();
        $data = $base . '/data';
        (new ProfileStore($data . '/profiles'))->save(SiteProfile::fromArray([
            'id' => 'shop', 'name' => 'Seeded source shop', 'type' => 'prestashop9', 'path' => '/shops/source', 'storage' => $base . '/storage',
        ]));

        // --- 1. back up (the command reads the database settings from the shop's own parameters.php)
        putenv('THREADSAFE_DATA=' . $data);
        try {
            [$code, $stdout, $stderr] = CliProcess::run(['profile', 'test', 'shop'], 900);
            self::assertSame(0, $code, $stdout . $stderr);
            [$code, $stdout, $stderr] = CliProcess::run(['backup', 'shop', '--report=' . $base . '/report.json'], 900);
            self::assertSame(0, $code, $stdout . $stderr);
            [$code, $stdout, $stderr] = CliProcess::run(['verify', 'shop'], 900);
            self::assertSame(0, $code, $stdout . $stderr);
        } finally {
            putenv('THREADSAFE_DATA');
        }
        $archives = glob($base . '/storage/shop_*.zip') ?: [];
        self::assertCount(1, $archives);
        $archive = $archives[0];
        $manifest = Manifest::fromArchive($archive);
        self::assertSame('9.0.2', $manifest->application['version']);
        self::assertGreaterThan(250, $manifest->counts['tables']);
        self::assertGreaterThan(10000, $manifest->counts['files']);
        self::assertSame('snapshot', $manifest->consistency);
        self::assertTrue(ZipReader::open($archive)->verify()->ok());
        [$ok, $output] = UnzipOracle::test($archive);
        self::assertTrue($ok, $output);

        // --- 2. wipe the target and put the backup there with the plain tools
        exec('find /shops/target -mindepth 1 -delete 2>&1', $ignored, $code);
        self::assertSame(0, $code, 'The target volume can be emptied.');
        $admin = new PDO('mysql:host=target-db;charset=utf8mb4', 'root', $rootPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $admin->exec('DROP DATABASE IF EXISTS prestashop_target');
        $admin->exec('CREATE DATABASE prestashop_target CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');

        $extracted = $base . '/extracted';
        mkdir($extracted);
        self::assertTrue(UnzipOracle::extract($archive, $extracted));
        exec('cp -a ' . escapeshellarg($extracted . '/files') . '/. /shops/target/ 2>&1', $ignored, $code);
        self::assertSame(0, $code);
        exec('chown -R www-data:www-data /shops/target 2>&1', $ignored, $code);
        self::assertSame(0, $code);

        $sqlFiles = glob($extracted . '/db/*.sql') ?: [];
        sort($sqlFiles);
        self::assertCount($manifest->counts['tables'], array_filter($sqlFiles, static fn (string $f): bool => !str_starts_with(basename($f), '_')));
        foreach ($sqlFiles as $file) {
            SqlClient::import('prestashop_target', $file, 'target-db', 'root', $rootPassword);
        }

        // --- 3. the copy equals the source: every table (except those that keep only their structure), every file
        $source = Stack::sourceDb();
        $target = Stack::targetDb();
        $prefix = 'ps_';
        $structureOnly = array_map(static fn (string $t): string => $prefix . $t, $manifest->filters['structureOnlyTables']);
        $listing = $source->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'prestashop' AND TABLE_TYPE = 'BASE TABLE' ORDER BY 1");
        self::assertNotFalse($listing);
        $tables = $listing->fetchAll(PDO::FETCH_COLUMN);
        self::assertGreaterThan(250, count($tables));
        // CHECKSUM TABLE is only comparable between servers of the same kind (MariaDB and MySQL compute it differently); a
        // restore from one into the other is compared row by row
        $sameKind = str_contains((string) self::scalar($source, 'SELECT VERSION()'), 'MariaDB') === str_contains((string) self::scalar($target, 'SELECT VERSION()'), 'MariaDB');
        $different = [];
        foreach ($tables as $table) {
            if (in_array($table, $structureOnly, true)) {
                self::assertSame('0', self::scalar($target, "SELECT COUNT(*) FROM `$table`"), "$table keeps only its structure");
                continue;
            }
            if (self::fingerprint($source, $table, $sameKind) !== self::fingerprint($target, $table, $sameKind)) {
                $different[] = $table;
            }
        }
        self::assertSame([], $different, 'Every table of the shop has the checksum of the source.');
        self::assertGreaterThan(200000, (int) self::scalar($source, 'SELECT COUNT(*) FROM ps_connections_source'), 'The source still has its 200,000 log rows ...');
        self::assertSame('0', self::scalar($target, 'SELECT COUNT(*) FROM ps_connections_source'), '... and the backup has none of them.');

        $files = 0;
        foreach (ZipReader::open($archive)->entries() as $entry) {
            if (!str_starts_with($entry->name, 'files/') || $entry->isDirectory) {
                continue;
            }
            $relative = substr($entry->name, 6);
            ++$files;
            if (md5_file('/shops/source/' . $relative) !== md5_file('/shops/target/' . $relative)) {
                self::fail("The file /$relative differs from the source.");
            }
        }
        self::assertSame($manifest->counts['files'], $files);
        foreach (['var/cache', 'cache/smarty/cache', 'cache/smarty/compile'] as $left) {
            self::assertDirectoryDoesNotExist('/shops/target/' . $left, "$left is left out by the default filters");
        }

        // --- 4. the shop comes back to life on the target domain (the self-test of the migration requests its pages)
        file_put_contents($base . '/db-password', "target\n");
        [$code, $stdout, $stderr] = CliProcess::run([
            'migrate', 'prestashop9',
            '--root=/shops/target', '--to=' . Stack::TARGET_HOST,
            '--db-host=target-db', '--db-name=prestashop_target', '--db-user=target', '--db-password-file=' . $base . '/db-password',
            '--server-path=/var/www/html',
            '--rules-dir=' . dirname(__DIR__) . '/fixtures/rules',
            '--self-test-url=' . Stack::TARGET_URL,
            '--journal-dir=' . $base . '/journal',
            '--yes',
        ], 900);
        self::assertSame(0, $code, $stderr . $stdout);
        self::assertStringContainsString('Run finished.', $stdout);

        $home = Stack::get(Stack::TARGET_URL, Stack::TARGET_HOST, '/');
        self::assertSame(200, $home->status);
        self::assertSame(Stack::TARGET_HOST, self::scalar($target, 'SELECT domain FROM ps_shop_url LIMIT 1'));
        self::assertSame(Stack::SOURCE_HOST, self::scalar($source, 'SELECT domain FROM ps_shop_url LIMIT 1'), 'The source was not touched.');
    }

    /**
     * Something that is equal for equal tables: CHECKSUM TABLE, or the sum of the CRC32 of every row (independent of the order).
     */
    private static function fingerprint(PDO $db, string $table, bool $checksum): string
    {
        if ($checksum) {
            return self::scalar($db, "CHECKSUM TABLE `$table`", 1);
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

    /**
     * The first (or $column-th) column of the first row of a query.
     */
    protected static function scalar(PDO $db, string $sql, int $column = 0): string
    {
        $statement = $db->query($sql);
        self::assertNotFalse($statement);
        $row = $statement->fetch(PDO::FETCH_NUM);

        return is_array($row) ? (string) $row[$column] : '';
    }
}
