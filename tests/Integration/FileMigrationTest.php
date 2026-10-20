<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use ThreadSafe\PrestaShop9\ParametersFile;
use ThreadSafe\Tests\Support\Stack;

/**
 * What the migration left in the files of the target stack (mounted at /shops/target, the source at /shops/source).
 * Runs after DatabaseMigrationTest, which performs the migration.
 */
final class FileMigrationTest extends IntegrationTestCase
{
    private const string TARGET = '/shops/target';

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_dir(self::TARGET . '/src/Core') || !is_dir('/shops/source/src/Core')) {
            self::markTestSkipped('The shop volumes are not mounted at /shops/source and /shops/target.');
        }
    }

    public function testParametersPointToTheTargetDatabaseAndKeepTheShopSecrets(): void
    {
        $target = ParametersFile::fromPath(self::TARGET . '/app/config/parameters.php');
        $source = ParametersFile::fromPath('/shops/source/app/config/parameters.php');

        self::assertSame('target-db', $target->get('database_host'));
        self::assertSame('prestashop_target', $target->get('database_name'));
        self::assertSame('target', $target->get('database_user'));
        self::assertSame('db', $source->get('database_host'), 'The source is untouched.');

        foreach (['secret', 'cookie_key', 'cookie_iv', 'new_cookie_key', 'api_public_key', 'api_private_key'] as $key) {
            self::assertSame($source->get($key), $target->get($key), "$key must survive the move unchanged.");
        }
        // Everything but the database values is byte-identical to the source file.
        $expected = $source->withDatabase($target->database());
        self::assertSame($expected->toString(), $target->toString());
    }

    public function testHtaccessUsesTheNewDomain(): void
    {
        $htaccess = (string) file_get_contents(self::TARGET . '/.htaccess');

        self::assertStringNotContainsString(Stack::SOURCE_HOST, $htaccess);
        self::assertStringContainsString('#Domain: ' . Stack::TARGET_HOST, $htaccess);
        self::assertStringContainsString('RewriteCond %{HTTP_HOST} ^' . Stack::TARGET_HOST . '$', $htaccess);
    }

    public function testHostBoundFilesAreFixed(): void
    {
        self::assertStringContainsString('error_log = /var/www/html/var/logs/php-errors.log;', (string) file_get_contents(self::TARGET . '/php.ini'));
        self::assertStringNotContainsString('/kunden/', (string) file_get_contents(self::TARGET . '/php.ini'));

        $sitemap = (string) file_get_contents(self::TARGET . '/1_index_sitemap.xml');
        self::assertStringContainsString('http://' . Stack::TARGET_HOST . '/1_de_0_sitemap.xml', $sitemap);
        self::assertStringNotContainsString(Stack::SOURCE_HOST, $sitemap);

        $mail = (string) file_get_contents(self::TARGET . '/mails/en/order_conf.html');
        self::assertStringContainsString(Stack::TARGET_HOST, $mail);
        self::assertStringNotContainsString(Stack::SOURCE_HOST, $mail);
    }

    public function testCachesAreGoneAndTheSkeletonIsBack(): void
    {
        self::assertDirectoryDoesNotExist(self::TARGET . '/var/cache/prod/ps_mainmenu');
        foreach (['var/logs', 'var/sessions', 'var/cache', 'img/tmp'] as $directory) {
            self::assertDirectoryExists(self::TARGET . '/' . $directory);
            self::assertSame(
                fileowner(self::TARGET),
                fileowner(self::TARGET . '/' . $directory),
                "$directory must belong to the owner of the shop so that the web server can write to it.",
            );
        }
    }

    public function testTheSourceFilesWereNotTouched(): void
    {
        self::assertStringContainsString('#Domain: ' . Stack::SOURCE_HOST, (string) file_get_contents('/shops/source/.htaccess'));
    }
}
