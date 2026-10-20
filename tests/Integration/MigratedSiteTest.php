<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use ThreadSafe\Tests\Support\Stack;

/**
 * The migrated target serves the shop under its new domain. These tests run in the second phase of
 * tests/stack/run.sh, after the migration (DatabaseMigrationTest) and after `oracle.sh target` has asked PrestaShop
 * itself what its .htaccess should look like.
 */
#[Group('after-migration')]
final class MigratedSiteTest extends IntegrationTestCase
{
    public function testHtaccessEqualsWhatPrestaShopRegeneratesForTheSameDatabase(): void
    {
        $current = self::oracleFile('target.current.htaccess');
        $regenerated = self::oracleFile('target.regenerated.htaccess');

        self::assertSame(self::managedBlock($regenerated), self::managedBlock($current));
        self::assertStringContainsString('RewriteCond %{HTTP_HOST} ^' . Stack::TARGET_HOST . '$', $current);
    }

    public function testHomePageAnswersUnderTheNewDomain(): void
    {
        $response = Stack::get(Stack::TARGET_URL, Stack::TARGET_HOST);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('</html>', $response->body);
        self::assertStringContainsString(Stack::TARGET_HOST, $response->body, 'Links and assets use the new domain.');
        self::assertStringNotContainsString(Stack::SOURCE_HOST, $response->body, 'No link may lead back to the old domain.');
    }

    public function testOtherFrontPagesAnswer(): void
    {
        foreach (['/index.php?controller=contact', '/index.php?controller=authentication', '/index.php?id_category=2&controller=category'] as $path) {
            $response = Stack::get(Stack::TARGET_URL, Stack::TARGET_HOST, $path);

            self::assertSame(200, $response->status, "GET $path");
            self::assertStringNotContainsString(Stack::SOURCE_HOST, $response->body, "GET $path");
        }
    }

    public function testAProductImageIsServedThroughItsFriendlyUrl(): void
    {
        $db = Stack::targetDb();
        $url = self::scalar($db, "SELECT CONCAT('/', i.id_image, '-', (SELECT name FROM ps_image_type WHERE products = 1 ORDER BY id_image_type LIMIT 1), '/', pl.link_rewrite, '.jpg')
            FROM ps_image i JOIN ps_product_lang pl ON pl.id_product = i.id_product AND pl.id_lang = 1 ORDER BY i.id_image LIMIT 1");
        self::assertNotSame('', $url, 'The demo data has product images.');

        $response = Stack::get(Stack::TARGET_URL, Stack::TARGET_HOST, $url);

        // These URLs only work through the host condition in .htaccess; with the old domain left in the file the
        // image would not be found.
        self::assertSame(200, $response->status, "GET $url");
        self::assertSame('image/jpeg', $response->header('content-type'));
    }

    public function testBackOfficeLoginPageAnswers(): void
    {
        $response = Stack::get(Stack::TARGET_URL, Stack::TARGET_HOST, '/admin-dev/');

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString(Stack::SOURCE_HOST, $response->body);
    }

    public function testTheOldDomainSendsVisitorsToTheNewOne(): void
    {
        $response = Stack::get(Stack::TARGET_URL, Stack::SOURCE_HOST);

        self::assertGreaterThanOrEqual(300, $response->status);
        self::assertLessThan(400, $response->status);
        self::assertStringContainsString(Stack::TARGET_HOST, (string) $response->header('location'));
    }
}
