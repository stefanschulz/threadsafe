<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Tests\Support\CliProcess;
use ThreadSafe\Tests\Support\ShopSmoke;
use ThreadSafe\Tests\Support\Stack;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The seeded shop restored with `threadsafe restore` into ANOTHER database with ANOTHER table prefix (`xy_` instead of `ps_`): the
 * backup holds the real table names, the dump is rewritten while it is imported, `parameters.php` is written for the new database and
 * prefix, and the shop serves (the self-test of the restore requests its pages). PrestaShop 9 has no views, triggers or foreign keys,
 * which is what makes the prefix change possible (docs/PHASE3.md 3.5).
 *
 * Wipes the target like the other tests of the group.
 */
#[Group('restore-variant')]
final class RestoreShopVariantTest extends IntegrationTestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    public function testTheShopComesBackInAnotherDatabaseWithAnotherPrefix(): void
    {
        if (!is_dir('/shops/source/src/Core') || !is_dir('/shops/target')) {
            self::markTestSkipped('The shop volumes are not mounted at /shops/source and /shops/target.');
        }
        $rootPassword = getenv('DB_ROOT_PASSWORD') ?: 'prestashop';
        $base = $this->tempDirectory();
        $data = $base . '/data';
        (new ProfileStore($data . '/profiles'))->save(SiteProfile::fromArray([
            'id' => 'shop', 'type' => 'prestashop9', 'path' => '/shops/source', 'storage' => $base . '/storage',
        ]));
        $admin = new PDO('mysql:host=target-db;charset=utf8mb4', 'root', $rootPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $admin->exec('DROP DATABASE IF EXISTS prestashop_variant');

        putenv('THREADSAFE_DATA=' . $data);
        try {
            [$code, $stdout, $stderr] = CliProcess::run(['backup', 'shop'], 900);
            self::assertSame(0, $code, $stdout . $stderr);
            $archive = (glob($base . '/storage/shop_*.zip') ?: [''])[0];

            exec('find /shops/target -mindepth 1 -delete 2>&1', $ignored, $code);
            self::assertSame(0, $code);
            exec('chown www-data:www-data /shops/target 2>&1', $ignored, $code);
            $admin->exec('CREATE DATABASE prestashop_variant CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
            // the target's own database stays empty: the earlier tests of the group left a shop in it
            $admin->exec('DROP DATABASE IF EXISTS prestashop_target');
            $admin->exec('CREATE DATABASE prestashop_target CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');

            file_put_contents($base . '/db-password', $rootPassword . "\n");
            [$code, $stdout, $stderr] = CliProcess::run([
                'restore', $archive, '--root=/shops/target', '--to=' . Stack::TARGET_HOST,
                '--db-host=target-db', '--db-name=prestashop_variant', '--db-user=root', '--db-password-file=' . $base . '/db-password', '--prefix=xy_',
                '--server-path=/var/www/html', '--rules-dir=' . dirname(__DIR__) . '/fixtures/rules', '--self-test-url=' . Stack::TARGET_URL,
                '--yes',
            ], 1800);
            self::assertSame(0, $code, $stderr . $stdout);
            self::assertStringContainsString('The table prefix is changed from "ps_" to "xy_"', $stdout);
        } finally {
            putenv('THREADSAFE_DATA');
        }

        // every table has the new prefix, none has the old one, and there are as many as in the source
        $tables = $admin->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'prestashop_variant' AND TABLE_TYPE = 'BASE TABLE'")?->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $sourceTables = Stack::sourceDb()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'prestashop' AND TABLE_TYPE = 'BASE TABLE'")?->fetchColumn();
        self::assertSame((int) $sourceTables, count($tables));
        self::assertSame([], array_values(array_filter($tables, static fn (mixed $t): bool => !str_starts_with((string) $t, 'xy_'))));
        $variant = new PDO('mysql:host=target-db;dbname=prestashop_variant;charset=utf8mb4', 'root', $rootPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (['product', 'product_lang', 'cms_lang', 'category', 'image', 'customer', 'orders'] as $table) {
            self::assertSame(
                (string) Stack::sourceDb()->query("SELECT COUNT(*) FROM ps_$table")?->fetchColumn(),
                (string) $variant->query("SELECT COUNT(*) FROM xy_$table")?->fetchColumn(),
                "$table has all its rows",
            );
        }

        // the shop was configured for the new database and prefix, and the old target database stays empty
        $parameters = (string) file_get_contents('/shops/target/app/config/parameters.php');
        self::assertStringContainsString("'database_name' => 'prestashop_variant'", $parameters);
        self::assertStringContainsString("'database_prefix' => 'xy_'", $parameters);
        self::assertSame('0', (string) Stack::targetDb()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'prestashop_target'")?->fetchColumn());

        $pages = ShopSmoke::visit(Stack::TARGET_URL, Stack::TARGET_HOST, ShopSmoke::paths($variant, 'xy_'));
        foreach ($pages as $name => $page) {
            self::assertSame(200, $page['status'], "$name answers");
        }

        $admin->exec('DROP DATABASE IF EXISTS prestashop_variant');
    }
}
