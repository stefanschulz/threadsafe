<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use stdClass;
use ThreadSafe\Core\ExitCode;
use ThreadSafe\Core\FileJournal;
use ThreadSafe\Core\NullJournal;
use ThreadSafe\Core\NullLogger;
use ThreadSafe\Core\Runner;
use ThreadSafe\Core\StepStatus;
use ThreadSafe\Core\Context;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\PrestaShop9\MigrationPipeline;
use ThreadSafe\Replace\DomainMapping;
use ThreadSafe\Tests\Support\Stack;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The whole migration (every step of the pipeline) against the real clone of the seeded PrestaShop 9 shop (tests/stack/run.sh clones first):
 * 298 tables, 200,000 log rows, serialized and JSON options, CMS text in all URL notations.
 *
 * The files of the target stack are mounted at /shops/target. parameters.php there still points to the source
 * database host, so the request names the target database explicitly. Runs after CloneTest (which expects the
 * untouched clone) and migrates the target's database and files; FileMigrationTest and MigratedSiteTest check the rest.
 */
final class DatabaseMigrationTest extends IntegrationTestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function request(): MigrationRequest
    {
        if (!is_dir('/shops/target/src/Core')) {
            self::markTestSkipped('The target volume is not mounted at /shops/target.');
        }

        return new MigrationRequest(
            '/shops/target',
            [new DomainMapping(Stack::SOURCE_HOST, Stack::TARGET_HOST)],
            database: new DatabaseSettings('target-db', 3306, 'prestashop_target', 'target', 'target', 'ps_'),
            serverPath: '/var/www/html',
        );
    }

    private function runner(): Runner
    {
        return new Runner(MigrationPipeline::steps());
    }

    private function context(MigrationRequest $request, bool $dryRun): Context
    {
        return new Context($request, $dryRun ? new NullJournal() : new FileJournal($this->tempDirectory()), new NullLogger(), $dryRun);
    }

    public function testMigratesTheClone(): void
    {
        $db = Stack::targetDb();
        $oldDomain = Stack::SOURCE_HOST;
        $newDomain = Stack::TARGET_HOST;
        $logRowsBefore = self::scalar($db, 'SELECT COUNT(*) FROM ps_connections_source');

        // --- dry run: reports a lot, changes nothing
        $started = microtime(true);
        $dry = $this->runner()->run($this->context($this->request(), dryRun: true), apply: false);
        self::assertSame(ExitCode::Ok, $dry->exitCode);
        self::assertGreaterThan(10, $dry->report->plannedChanges());
        self::assertSame($oldDomain, self::scalar($db, 'SELECT domain FROM ps_shop_url LIMIT 1'));
        self::assertLessThan(60, microtime(true) - $started, 'The dry run must skip the 200,000-row log table.');

        // --- apply
        $applied = $this->runner()->run($this->context($this->request(), dryRun: false), apply: true);
        self::assertSame(ExitCode::Ok, $applied->exitCode, (string) $applied->failure?->getMessage());
        self::assertSame($newDomain, self::scalar($db, 'SELECT domain FROM ps_shop_url LIMIT 1'));
        self::assertSame($newDomain, self::scalar($db, "SELECT domain_ssl FROM ps_shop_url LIMIT 1"));
        self::assertSame($newDomain, self::scalar($db, "SELECT value FROM ps_configuration WHERE name = 'PS_SHOP_DOMAIN'"));
        self::assertSame($newDomain, self::scalar($db, "SELECT value FROM ps_configuration WHERE name = 'PS_SHOP_DOMAIN_SSL'"));

        // --- caches
        self::assertSame('0', self::scalar($db, 'SELECT COUNT(*) FROM ps_smarty_cache'));

        // --- skipped log table is untouched
        self::assertSame($logRowsBefore, self::scalar($db, 'SELECT COUNT(*) FROM ps_connections_source'));
        self::assertGreaterThanOrEqual(200000, (int) self::scalar($db, "SELECT COUNT(*) FROM ps_connections_source WHERE http_referer LIKE '%$oldDomain%'"));

        // --- the source database was not touched
        self::assertSame($oldDomain, self::scalar(Stack::sourceDb(), 'SELECT domain FROM ps_shop_url LIMIT 1'));

        // --- a second run finds nothing to do
        $again = $this->runner()->run($this->context($this->request(), dryRun: false), apply: true);
        self::assertSame(ExitCode::Ok, $again->exitCode);
        self::assertSame(0, $again->report->plannedChanges());
        foreach ($again->report->steps as $step) {
            self::assertSame(StepStatus::Unchanged, $step->status, $step->id);
        }
    }

    /** Depends on the migration above having run; PHPUnit runs the tests of a class in order. */
    public function testCmsContentKeepsEveryNotationAndEveryLookAlike(): void
    {
        $db = Stack::targetDb();
        $content = self::scalar($db, 'SELECT content FROM ps_cms_lang WHERE content LIKE \'%Seed:%\' ORDER BY id_cms, id_lang LIMIT 1');
        $new = Stack::TARGET_HOST;
        $encodedNew = str_replace(':', '%3A', $new);

        self::assertStringContainsString("http://$new/content/seed", $content, 'The www. variant becomes the plain new domain.');
        self::assertStringContainsString("https://$new/en/content/1-delivery", $content);
        self::assertStringContainsString("//$new/img/seed.png", $content);
        self::assertStringContainsString("http%3A%2F%2F$encodedNew%2Fcart", $content, 'The URL-encoded form follows, with the port encoded too.');
        self::assertStringContainsString('http:\/\/' . $new . '\/escaped', $content, 'The JSON-escaped notation is kept.');
        self::assertStringContainsString('http://other.test/page', $content, 'A foreign domain stays.');
        self::assertStringContainsString('info@other.test', $content);
        self::assertStringContainsString('Bare mention: ' . Stack::SOURCE_HOST, $content, 'A bare host name is reported, not replaced.');
    }

    public function testSerializedFixtureOptionStaysValid(): void
    {
        $db = Stack::targetDb();
        $new = Stack::TARGET_HOST;
        $raw = self::scalar($db, "SELECT value FROM ps_configuration WHERE name = 'THREADSAFE_FIXTURE_SERIALIZED'");

        $data = unserialize($raw, ['allowed_classes' => [stdClass::class]]);

        self::assertIsArray($data);
        self::assertSame("http://$new/", $data['home']);
        self::assertSame(["http://$new/a", "http://$new/b", "//$new/c"], $data['links']);
        self::assertSame("http://$new/module/threadsafe_fixture/callback", $data['meta']['callback']);
        self::assertSame('http://other.test/x', $data['meta']['foreign']);
        self::assertSame('info@other.test', $data['meta']['mail']);
        self::assertSame(Stack::SOURCE_HOST, $data['meta']['bare'], 'A bare host name inside a serialized value is not replaced.');
        self::assertInstanceOf(stdClass::class, $data['object']);
        self::assertSame("http://$new/object", $data['object']->url);
        self::assertSame(['u' => "http://$new/nested", 'umlaut' => 'Größe ✓'], json_decode($data['nested'], true));
    }

    public function testJsonFixtureOptionStaysValidAndKeepsItsFormat(): void
    {
        $db = Stack::targetDb();
        $new = Stack::TARGET_HOST;
        $raw = self::scalar($db, "SELECT value FROM ps_configuration WHERE name = 'THREADSAFE_FIXTURE_JSON'");

        self::assertTrue(json_validate($raw));
        self::assertStringContainsString('"shop":"http:\/\/' . $new . '\/"', $raw, 'Escaped slashes stay escaped.');
        self::assertStringContainsString('"text":"Gr\u00f6\u00dfe \u2713"', $raw, 'Escaped unicode stays escaped.');
        self::assertStringEndsWith('"number":1}', $raw, 'Everything outside the replaced strings stays byte-identical.');
        self::assertStringNotContainsString(Stack::SOURCE_HOST, $raw);
    }

    public function testPlainFixtureValuesAndTables(): void
    {
        $db = Stack::targetDb();
        $new = Stack::TARGET_HOST;

        self::assertSame("http://$new/plain/url", self::scalar($db, "SELECT value FROM ps_configuration WHERE name = 'THREADSAFE_FIXTURE_URL'"));
        self::assertSame("http://$new/module/threadsafe_fixture/hook?token=abc", self::scalar($db, "SELECT value FROM ps_configuration WHERE name = 'THREADSAFE_FIXTURE_WEBHOOK'"));
        self::assertSame(Stack::SOURCE_HOST, self::scalar($db, "SELECT value FROM ps_configuration WHERE name = 'THREADSAFE_FIXTURE_BARE'"));
        self::assertSame(0, (int) self::scalar($db, "SELECT COUNT(*) FROM ps_threadsafe_fixture_item WHERE url LIKE '%" . Stack::SOURCE_HOST . "%'"));
        self::assertSame(0, (int) self::scalar($db, "SELECT COUNT(*) FROM ps_threadsafe_fixture_item_lang WHERE text LIKE '%http://" . Stack::SOURCE_HOST . "%'"));

        $serializedPayload = self::scalar($db, "SELECT payload FROM ps_threadsafe_fixture_item WHERE name = 'serialized'");
        self::assertSame(['url' => "http://$new/item/2", 'n' => 2, 'tags' => ['a', 'b']], unserialize($serializedPayload));
        self::assertStringContainsString('info@other.test', self::scalar($db, "SELECT payload FROM ps_threadsafe_fixture_item WHERE name = 'plain'"));
    }
}
