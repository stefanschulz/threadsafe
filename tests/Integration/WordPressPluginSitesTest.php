<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use ThreadSafe\Backup\Manifest;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Tests\Support\CliProcess;
use ThreadSafe\Tests\Support\TableCompare;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Tests\Support\WordPressClient;

/**
 * Sites with the plugins of real sites (docs/PHASE9.md, field test in Docker; tests/stack/seed-wordpress-plugins.sh): a shop (WooCommerce, Yoast
 * SEO, Redis Object Cache) and a site with W3 Total Cache and Elementor are backed up and restored on another domain with another table prefix, and
 * what these plugins keep must work and must not name the old address.
 *
 * Not part of tests/stack/run.sh: the seed downloads the plugins from wordpress.org, so it needs the internet. Run it by hand after the seed:
 * `phpunit --testsuite integration --group wordpress-plugins`. It wipes the target volume.
 */
#[Group('wordpress-plugins')]
final class WordPressPluginSitesTest extends IntegrationTestCase
{
    use TempDirectory;

    private const string TARGET = '/shops/target';
    private const string TARGET_URL = 'http://target-wordpress';
    private const string TARGET_HOST = 'localhost:8291';
    private const string PASSWORD = 'Seed-admin-0123456789';

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private static function password(): string
    {
        return getenv('DB_ROOT_PASSWORD') ?: 'prestashop';
    }

    private static function db(string $host, string $database): PDO
    {
        return new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", 'root', self::password(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function requireSite(string $directory): void
    {
        if (!is_file($directory . '/.threadsafe-seeded') || !is_dir(self::TARGET)) {
            self::markTestSkipped('The plugin sites are not seeded (tests/stack/seed-wordpress-plugins.sh) or the target is missing.');
        }
    }

    /**
     * The backup of a site through its profile, and its restore onto the empty target with another prefix.
     *
     * @return array{Manifest, string} the manifest and the table prefix of the restored site
     */
    private function backUpAndRestore(string $directory): array
    {
        $base = $this->tempDirectory();
        (new ProfileStore($base . '/data/profiles'))->save(SiteProfile::fromArray([
            'id' => 'site', 'name' => 'Plugin site', 'type' => 'wordpress', 'path' => $directory, 'storage' => $base . '/storage',
        ]));
        putenv('THREADSAFE_DATA=' . $base . '/data');
        try {
            foreach ([['profile', 'test', 'site'], ['backup', 'site'], ['verify', 'site']] as $command) {
                [$code, $stdout, $stderr] = CliProcess::run($command, 900);
                self::assertSame(0, $code, implode(' ', $command) . ': ' . $stdout . $stderr);
            }
            $archives = glob($base . '/storage/site_*.zip') ?: [];
            self::assertCount(1, $archives);

            exec('find ' . escapeshellarg(self::TARGET) . ' -mindepth 1 -delete 2>&1', $ignored, $code);
            self::assertSame(0, $code);
            exec('chown www-data:www-data ' . escapeshellarg(self::TARGET) . ' 2>&1', $ignored, $code);
            self::assertSame(0, $code);
            $admin = new PDO('mysql:host=target-db;charset=utf8mb4', 'root', self::password(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $admin->exec('DROP DATABASE IF EXISTS `wp_target`');
            $admin->exec('CREATE DATABASE `wp_target` CHARACTER SET utf8mb4');
            file_put_contents($base . '/db-password', self::password() . "\n");

            [$code, $stdout, $stderr] = CliProcess::run([
                'restore', $archives[0], '--root=' . self::TARGET, '--to=' . self::TARGET_HOST, '--prefix=wp2_',
                '--db-host=target-db', '--db-name=wp_target', '--db-user=root', '--db-password-file=' . $base . '/db-password',
                '--server-path=/var/www/html', '--self-test-url=' . self::TARGET_URL, '--yes',
            ], 1800);
            self::assertSame(0, $code, $stderr . $stdout);
        } finally {
            putenv('THREADSAFE_DATA');
        }

        return [Manifest::fromArchive($archives[0]), 'wp2_'];
    }

    /** What still names the old host in the files of the restored site (the journal of the restore does, on purpose). */
    private function filesNaming(string $host): string
    {
        exec('grep -rIl -F -e ' . escapeshellarg($host) . ' -e ' . escapeshellarg(str_replace(':', '%3A', $host)) . ' ' . escapeshellarg(self::TARGET) . ' 2>/dev/null | grep -v /.threadsafe-restore/', $found);

        return implode("\n", $found);
    }

    // ------------------------------------------------------------------------------------------------- the shop

    public function testAShopWithWooCommerceYoastAndRedisIsRestoredAndWorksOnItsNewAddress(): void
    {
        $this->requireSite('/wordpressshop');
        $old = 'localhost:8200';
        [$manifest, $prefix] = $this->backUpAndRestore('/wordpressshop');

        self::assertContains('woocommerce/woocommerce.php', $manifest->extras['activePlugins']);
        self::assertContains('wordpress-seo/wp-seo.php', $manifest->extras['activePlugins']);
        self::assertSame(['object-cache.php'], $manifest->extras['dropIns']);
        self::assertContains('woocommerce_sessions', $manifest->filters['structureOnlyTables']);

        // the drop-in that needs Redis is off, and the site does not notice
        self::assertFileDoesNotExist(self::TARGET . '/wp-content/object-cache.php');
        self::assertFileExists(self::TARGET . '/wp-content/object-cache.php.threadsafe-off');

        // the data of the shop is whole
        $source = self::db('db', 'wpshop');
        $target = self::db('target-db', 'wp_target');
        foreach (['shop_order', 'product', 'product_variation', 'shop_coupon'] as $type) {
            self::assertSame(
                TableCompare::cell($source, "SELECT COUNT(*) FROM wp_posts WHERE post_type = '$type'"),
                TableCompare::cell($target, "SELECT COUNT(*) FROM {$prefix}posts WHERE post_type = '$type'"),
                "the posts of the type $type",
            );
        }
        self::assertSame('6', TableCompare::cell($target, "SELECT COUNT(*) FROM {$prefix}posts WHERE post_type = 'shop_order'"));
        self::assertSame(
            TableCompare::cell($source, "SELECT SUM(meta_value + 0) FROM wp_postmeta WHERE meta_key = '_order_total'"),
            TableCompare::cell($target, "SELECT SUM(meta_value + 0) FROM {$prefix}postmeta WHERE meta_key = '_order_total'"),
            'the totals of the orders',
        );
        self::assertSame('0', TableCompare::cell($target, "SELECT COUNT(*) FROM {$prefix}woocommerce_sessions"), 'the sessions are not in a backup');
        self::assertGreaterThan(0, (int) TableCompare::cell($source, 'SELECT COUNT(*) FROM wp_woocommerce_sessions'));

        // the address of the own site is replaced where the plugins keep it, the address of other services is not
        self::assertSame('http://' . self::TARGET_HOST . '/wp-json/seed/hook', TableCompare::cell($target, "SELECT delivery_url FROM {$prefix}wc_webhooks WHERE name = 'Order created (own site)'"));
        self::assertSame('https://crm.example.net/woo/hook', TableCompare::cell($target, "SELECT delivery_url FROM {$prefix}wc_webhooks WHERE name = 'Order created (CRM)'"));
        self::assertSame('0', TableCompare::cell($target, "SELECT COUNT(*) FROM {$prefix}yoast_indexable WHERE permalink LIKE '%$old%'"), 'the indexables of Yoast SEO');
        self::assertGreaterThan(10, (int) TableCompare::cell($target, "SELECT COUNT(*) FROM {$prefix}yoast_indexable WHERE permalink LIKE '%" . self::TARGET_HOST . "%'"));
        self::assertSame('0', TableCompare::cell($target, "SELECT COUNT(*) FROM {$prefix}wc_product_download_directories WHERE url LIKE '%$old%'"));
        self::assertSame('', $this->filesNaming($old), 'no file of the restored shop names the old host');

        // the shop serves
        $client = new WordPressClient(self::TARGET_URL, self::TARGET_HOST, '');
        foreach (['/', '/shop/', '/product/seed-t-shirt/', '/product/seed-hoodie/', '/cart/', '/my-account/', '/sitemap_index.xml', '/wp-json/wc/store/v1/products'] as $path) {
            $answer = $client->get($path);
            self::assertSame(200, $answer['status'], "$path answers");
            self::assertStringNotContainsString($old, $answer['body'], "$path names the old host");
        }
        $products = json_decode($client->get('/wp-json/wc/store/v1/products')['body'], true);
        self::assertIsArray($products);
        self::assertCount(5, $products, 'the products of the Store API');
        self::assertStringContainsString('http://' . self::TARGET_HOST . '/product/', json_encode($products, JSON_UNESCAPED_SLASHES) ?: '');

        // the administrator works the shop
        $admin = new WordPressClient(self::TARGET_URL, self::TARGET_HOST, '');
        self::assertTrue($admin->login('seedadmin', self::PASSWORD));
        foreach (['/wp-admin/', '/wp-admin/edit.php?post_type=product', '/wp-admin/edit.php?post_type=shop_order', '/wp-admin/admin.php?page=wc-settings', '/wp-admin/admin.php?page=wc-status', '/wp-admin/admin.php?page=wpseo_dashboard', '/wp-admin/options-general.php?page=redis-cache'] as $path) {
            $answer = $admin->get($path);
            self::assertSame(200, $answer['status'], "$path answers for the administrator");
            self::assertStringNotContainsString($old, $answer['body'], "$path names the old host");
        }
        self::assertStringContainsString('Order', $admin->get('/wp-admin/edit.php?post_type=shop_order')['body']);
    }

    // ------------------------------------------------------------------------------------------------- caching and a page builder

    public function testASiteWithW3TotalCacheAndElementorIsRestoredAndTheGeneratedCssFollowsTheNewAddress(): void
    {
        $this->requireSite('/wordpresscache');
        $old = 'localhost:8201';
        $source = '/wordpresscache/wp-content/uploads/elementor/css/post-5.css';
        self::assertStringContainsString($old, (string) file_get_contents($source), 'the source CSS names the own address, as the test needs');
        [$manifest, $prefix] = $this->backUpAndRestore('/wordpresscache');

        self::assertEqualsCanonicalizing(['advanced-cache.php', 'object-cache.php', 'db.php'], $manifest->extras['dropIns']);
        foreach (['advanced-cache.php', 'object-cache.php', 'db.php'] as $dropIn) {
            self::assertFileDoesNotExist(self::TARGET . "/wp-content/$dropIn", "$dropIn is switched off");
            self::assertFileExists(self::TARGET . "/wp-content/$dropIn.threadsafe-off");
        }

        // what Elementor generated is gone: the files, and the record that says they exist
        $target = self::db('target-db', 'wp_target');
        self::assertFileDoesNotExist(self::TARGET . '/wp-content/uploads/elementor/css/post-5.css');
        self::assertSame('0', TableCompare::cell($target, "SELECT COUNT(*) FROM {$prefix}postmeta WHERE meta_key = '_elementor_css'"));
        self::assertSame('1', TableCompare::cell($target, "SELECT COUNT(*) FROM {$prefix}postmeta WHERE meta_key = '_elementor_data'"), 'the page itself is content and stays');
        self::assertStringContainsString('localhost:8291', TableCompare::cell($target, "SELECT meta_value FROM {$prefix}postmeta WHERE meta_key = '_elementor_data'"), 'its data names the new address');
        self::assertStringNotContainsString($old, TableCompare::cell($target, "SELECT meta_value FROM {$prefix}postmeta WHERE meta_key = '_elementor_data'"));
        self::assertSame('', $this->filesNaming($old), 'no file of the restored site names the old host');

        // a visitor sees the page, and Elementor makes the CSS again, with the new address
        $client = new WordPressClient(self::TARGET_URL, self::TARGET_HOST, '');
        foreach (['/', '/about-elementor/', '/hello-world/'] as $path) {
            $answer = $client->get($path);
            self::assertSame(200, $answer['status'], "$path answers");
            self::assertStringNotContainsString($old, $answer['body']);
        }
        self::assertStringContainsString('Built with Elementor', $client->get('/about-elementor/')['body']);
        $css = self::TARGET . '/wp-content/uploads/elementor/css/post-5.css';
        self::assertFileExists($css, 'Elementor made the CSS of the page again');
        self::assertStringContainsString('http://' . self::TARGET_HOST . '/wp-content/uploads/seed/photo.png', (string) file_get_contents($css));
        self::assertStringNotContainsString($old, (string) file_get_contents($css));

        // the administrator sees both plugins
        $admin = new WordPressClient(self::TARGET_URL, self::TARGET_HOST, '');
        self::assertTrue($admin->login('seedadmin', self::PASSWORD));
        foreach (['/wp-admin/', '/wp-admin/admin.php?page=elementor', '/wp-admin/admin.php?page=w3tc_dashboard'] as $path) {
            self::assertSame(200, $admin->get($path)['status'], "$path answers for the administrator");
        }
    }
}
