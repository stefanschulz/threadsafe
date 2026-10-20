<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use ThreadSafe\Tests\Support\Stack;

/**
 * Baseline: the source stack is healthy and carries the seeded problem cases. If this fails, nothing that follows
 * (cloning, migrating) can be judged.
 */
final class SourceStackTest extends IntegrationTestCase
{
    public function testDatabaseIsMariaDb(): void
    {
        self::assertStringContainsString('MariaDB', self::scalar(Stack::sourceDb(), 'SELECT VERSION()'));
    }

    public function testHomePageAnswers(): void
    {
        $response = Stack::get(Stack::SOURCE_URL, Stack::SOURCE_HOST);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('</html>', $response->body);
    }

    public function testOtherFrontPagesAnswer(): void
    {
        foreach (['/index.php?controller=contact', '/index.php?controller=authentication', '/index.php?id_category=2&controller=category'] as $path) {
            $response = Stack::get(Stack::SOURCE_URL, Stack::SOURCE_HOST, $path);

            self::assertSame(200, $response->status, "GET $path");
        }
    }

    public function testShopIsConfiguredForTheSourceDomain(): void
    {
        $db = Stack::sourceDb();

        self::assertSame(Stack::SOURCE_HOST, self::scalar($db, "SELECT value FROM ps_configuration WHERE name = 'PS_SHOP_DOMAIN'"));
        self::assertSame(Stack::SOURCE_HOST, self::scalar($db, 'SELECT domain FROM ps_shop_url LIMIT 1'));
    }

    public function testFixtureModuleIsInstalled(): void
    {
        $db = Stack::sourceDb();

        self::assertSame('1', self::scalar($db, "SELECT active FROM ps_module WHERE name = 'threadsafe_fixture'"));
        self::assertSame('3', self::scalar($db, 'SELECT COUNT(*) FROM ps_threadsafe_fixture_item'));
    }

    public function testProblemCasesAreSeeded(): void
    {
        $db = Stack::sourceDb();

        self::assertSame('1', self::scalar($db, "SELECT COUNT(*) FROM ps_configuration WHERE name = 'THREADSAFE_SEEDED'"));
        self::assertGreaterThanOrEqual(200000, (int) self::scalar($db, 'SELECT COUNT(*) FROM ps_connections_source'));
        self::assertGreaterThan(0, (int) self::scalar($db, "SELECT COUNT(*) FROM ps_cms_lang WHERE content LIKE '%http://www.localhost:8190/content/seed%'"));
        self::assertSame('1', self::scalar($db, 'SELECT COUNT(*) FROM ps_smarty_cache'));
    }

    public function testSerializedAndJsonFixtureValuesAreValid(): void
    {
        $db = Stack::sourceDb();

        $serialized = self::scalar($db, "SELECT value FROM ps_configuration WHERE name = 'THREADSAFE_FIXTURE_SERIALIZED'");
        self::assertIsArray(unserialize($serialized, ['allowed_classes' => [\stdClass::class]]));

        $json = self::scalar($db, "SELECT value FROM ps_configuration WHERE name = 'THREADSAFE_FIXTURE_JSON'");
        self::assertTrue(json_validate($json));
        self::assertStringContainsString('http:\/\/localhost:8190\/', $json, 'The fixture JSON must use escaped slashes.');
    }

    public function testHtaccessOracleReproducesTheCurrentFile(): void
    {
        $current = self::oracleFile('source.current.htaccess');
        $regenerated = self::oracleFile('source.regenerated.htaccess');

        self::assertSame(self::managedBlock($regenerated), self::managedBlock($current));
        self::assertStringContainsString('RewriteCond %{HTTP_HOST} ^localhost:8190$', $current);
    }
}
