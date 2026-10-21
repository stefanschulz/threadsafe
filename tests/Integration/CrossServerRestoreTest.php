<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use ThreadSafe\Backup\Manifest;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Restore\RestoreRun;
use ThreadSafe\Tests\Support\CliProcess;
use ThreadSafe\Tests\Support\ShopCompare;
use ThreadSafe\Tests\Support\ShopSmoke;
use ThreadSafe\Tests\Support\Stack;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Tests\Support\UploadedBackup;

/**
 * A backup from one kind of database server restored onto the other (docs/PHASE3.md 4.2 item 7): the source stack runs on one
 * kind (MySQL 8.4 or MariaDB 10.11), the target's database on the other. Run by `tests/stack/cross.sh`, which makes that mix;
 * on a stack with the same server on both sides the tests are skipped.
 *
 * 1. the seeded shop goes over from MariaDB to MySQL with `threadsafe restore`, adapted and self-tested, and every table has the rows
 *    of the source. From MySQL to MariaDB it is refused: PrestaShop on MySQL 8 creates tables with the collation utf8mb4_0900_ai_ci,
 *    which MariaDB does not have (docs/PHASE3.md 7, decision 3 of the risks); the refusal says so before anything is written;
 * 2. a MySQL 8 collation in a small database of its own is refused the same way (`RestoreRun::inspect`).
 */
#[Group('cross-server')]
final class CrossServerRestoreTest extends IntegrationTestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /** @return array{string, string} the kind of the source's and of the target's database server: mariadb or mysql */
    private static function kinds(): array
    {
        $source = (string) Stack::sourceDb()->query('SELECT VERSION()')?->fetchColumn();
        $target = (string) Stack::targetDb()->query('SELECT VERSION()')?->fetchColumn();

        return [str_contains($source, 'MariaDB') ? 'mariadb' : 'mysql', str_contains($target, 'MariaDB') ? 'mariadb' : 'mysql'];
    }

    private function needsTwoKinds(): void
    {
        if (!is_dir('/shops/source/src/Core') || !is_dir('/shops/target')) {
            self::markTestSkipped('The shop volumes are not mounted.');
        }
        [$source, $target] = self::kinds();
        if ($source === $target) {
            self::markTestSkipped('Source and target database are the same kind of server (tests/stack/cross.sh makes the mix).');
        }
    }

    public function testTheShopCrossesFromOneKindOfServerToTheOther(): void
    {
        $this->needsTwoKinds();
        [$sourceKind, $targetKind] = self::kinds();
        $rootPassword = getenv('DB_ROOT_PASSWORD') ?: 'prestashop';
        $base = $this->tempDirectory();
        $data = $base . '/data';
        (new ProfileStore($data . '/profiles'))->save(SiteProfile::fromArray([
            'id' => 'shop', 'type' => 'prestashop9', 'path' => '/shops/source', 'storage' => $base . '/storage',
        ]));
        putenv('THREADSAFE_DATA=' . $data);
        try {
            [$code, $stdout, $stderr] = CliProcess::run(['backup', 'shop'], 900);
            self::assertSame(0, $code, $stdout . $stderr);
            $archive = (glob($base . '/storage/shop_*.zip') ?: [''])[0];
            $manifest = Manifest::fromArchive($archive);
            self::assertSame($sourceKind, $manifest->database['server'], 'the backup says which kind of server it comes from');

            exec('find /shops/target -mindepth 1 -delete 2>&1', $ignored, $code);
            self::assertSame(0, $code);
            exec('chown www-data:www-data /shops/target 2>&1', $ignored, $code);
            $admin = new PDO('mysql:host=target-db;charset=utf8mb4', 'root', $rootPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $admin->exec('DROP DATABASE IF EXISTS prestashop_target');
            $admin->exec('CREATE DATABASE prestashop_target CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');

            file_put_contents($base . '/db-password', "target\n");
            [$code, $stdout, $stderr] = CliProcess::run([
                'restore', $archive, '--root=/shops/target', '--to=' . Stack::TARGET_HOST,
                '--db-host=target-db', '--db-name=prestashop_target', '--db-user=target', '--db-password-file=' . $base . '/db-password',
                '--server-path=/var/www/html', '--rules-dir=' . dirname(__DIR__) . '/fixtures/rules', '--self-test-url=' . Stack::TARGET_URL,
                '--yes',
            ], 1800);
            if ($sourceKind === 'mysql' && $targetKind === 'mariadb') {
                self::assertSame(2, $code, 'refused: ' . $stderr . $stdout);
                self::assertStringContainsString('utf8mb4_0900_ai_ci', $stderr);
                self::assertStringContainsString('MariaDB does not have', $stderr);
                self::assertSame([], array_values(array_diff(scandir('/shops/target') ?: [], ['.', '..'])), 'nothing was written');
                self::assertSame('0', (string) $admin->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'prestashop_target'")?->fetchColumn());

                return;
            }
            self::assertSame(0, $code, $stderr . $stdout);
            self::assertStringContainsString("The backup was made on $sourceKind", $stdout, 'the preflight tells that the kind of server differs');
        } finally {
            putenv('THREADSAFE_DATA');
        }

        $differences = ShopCompare::differences(
            Stack::sourceDb(),
            'ps_',
            Stack::targetDb(),
            'ps_',
            Stack::SOURCE_HOST,
            array_map(static fn (string $t): string => 'ps_' . $t, $manifest->filters['structureOnlyTables']),
        );
        self::assertSame([], $differences, "tables that are not what the $sourceKind source had, on $targetKind");
        foreach (ShopSmoke::visit(Stack::TARGET_URL, Stack::TARGET_HOST, ShopSmoke::paths(Stack::targetDb())) as $name => $page) {
            self::assertSame(200, $page['status'], "$name answers");
        }
    }

    public function testAMySqlCollationIsRefusedOnMariaDbBeforeAnythingIsWritten(): void
    {
        $this->needsTwoKinds();
        [$sourceKind, $targetKind] = self::kinds();
        if ($sourceKind !== 'mysql' || $targetKind !== 'mariadb') {
            self::markTestSkipped('The refusal is for a backup made on MySQL 8 and restored onto MariaDB.');
        }
        $rootPassword = getenv('DB_ROOT_PASSWORD') ?: 'prestashop';
        $source = new PDO('mysql:host=db;charset=utf8mb4', 'root', $rootPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $target = new PDO('mysql:host=target-db;charset=utf8mb4', 'root', $rootPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $name = 'threadsafe_0900_' . bin2hex(random_bytes(3));
        $source->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
        $target->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
        try {
            $source->exec("CREATE TABLE `$name`.words (id INT PRIMARY KEY, word VARCHAR(50) COLLATE utf8mb4_0900_ai_ci) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
            $source->exec("INSERT INTO `$name`.words VALUES (1, 'Käse'), (2, 'Kase')");
            $base = $this->tempDirectory();
            mkdir($base . '/web');
            $parts = UploadedBackup::make($base . '/work', $base . '/web', $name, $rootPassword);

            $task = new RestoreRun(PluginRegistry::load());
            $inspection = $task->inspect($task->initialState([
                'archive' => $parts[0], 'root' => $base . '/restored', 'work' => $base . '/state',
                'settings' => ['selfTest' => false, 'database' => ['host' => 'target-db', 'port' => 3306, 'name' => $name, 'user' => 'root', 'password' => $rootPassword, 'prefix' => '']],
            ]));

            self::assertFalse($inspection->ok());
            self::assertStringContainsString('utf8mb4_0900_ai_ci', $inspection->errorMessage());
            self::assertStringContainsString('MariaDB does not have', $inspection->errorMessage());
            self::assertStringContainsString('The backup was made on mysql', implode("\n", array_map(static fn ($c): string => $c->message, $inspection->checks)));
            self::assertDirectoryDoesNotExist($base . '/restored');
            self::assertSame('0', (string) $target->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$name'")?->fetchColumn());
        } finally {
            $source->exec("DROP DATABASE IF EXISTS `$name`");
            $target->exec("DROP DATABASE IF EXISTS `$name`");
        }
    }
}
