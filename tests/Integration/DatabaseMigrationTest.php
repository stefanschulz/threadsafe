<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use stdClass;
use ThreadSafe\Tests\Support\CliProcess;
use ThreadSafe\Tests\Support\Stack;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The whole migration, started the way a user starts it – `threadsafe migrate prestashop9` as a real process –
 * against the real clone of the seeded PrestaShop 9 shop (tests/stack/run.sh clones first): 298 tables, 200,000 log
 * rows, serialized and JSON options, CMS text in all URL notations.
 *
 * The files of the target stack are mounted at /shops/target. parameters.php there still points to the source
 * database host, so the command is told the target database. Runs after CloneTest (which expects the untouched
 * clone) and migrates the target's database and files; FileMigrationTest and MigratedSiteTest check the rest.
 */
final class DatabaseMigrationTest extends IntegrationTestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @return list<string> the command line without the options that differ between the runs
     */
    private function command(string $directory, string $to = Stack::TARGET_HOST): array
    {
        if (!is_dir('/shops/target/src/Core')) {
            self::markTestSkipped('The target volume is not mounted at /shops/target.');
        }
        file_put_contents($directory . '/db-password', "target\n");

        return [
            'migrate', 'prestashop9',
            '--root=/shops/target',
            '--to=' . $to,
            '--db-host=target-db', '--db-name=prestashop_target', '--db-user=target',
            '--db-password-file=' . $directory . '/db-password',
            '--server-path=/var/www/html',
            '--rules-dir=' . dirname(__DIR__) . '/fixtures/rules',
            '--journal-dir=' . $directory . '/journal',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function report(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($data);

        return $data;
    }

    public function testMigratesTheClone(): void
    {
        $db = Stack::targetDb();
        $oldDomain = Stack::SOURCE_HOST;
        $newDomain = Stack::TARGET_HOST;
        $logRowsBefore = self::scalar($db, 'SELECT COUNT(*) FROM ps_connections_source');
        $directory = $this->tempDirectory();
        $command = $this->command($directory);

        // --- without --yes: plans, reports, changes nothing
        $started = microtime(true);
        [$code, $stdout, $stderr] = CliProcess::run([...$command, '--report=' . $directory . '/dry.json']);
        self::assertSame(0, $code, $stderr);
        self::assertStringContainsString('Dry run – nothing was changed.', $stdout);
        self::assertStringContainsString("Old domain taken from the shop's database: $oldDomain", $stdout, 'The old domain is found without --from.');
        self::assertStringContainsString('Run the same command with --yes', $stdout);
        self::assertStringContainsString('Module rules in use: threadsafe_fixture.', $stdout);
        self::assertStringContainsString('No module rule for table "threadsafe_fixture_item_lang"', $stdout, 'A module table no rule describes is pointed out.');
        self::assertStringNotContainsString('No module rule for table "threadsafe_fixture_item"', $stdout);
        $dry = $this->report($directory . '/dry.json');
        self::assertTrue($dry['dryRun']);
        self::assertGreaterThan(10, $dry['plannedChanges']);
        self::assertSame($oldDomain, self::scalar($db, 'SELECT domain FROM ps_shop_url LIMIT 1'));
        self::assertDirectoryDoesNotExist($directory . '/journal', 'A dry run writes no journal.');
        self::assertLessThan(60, microtime(true) - $started, 'The dry run must skip the 200,000-row log table.');

        // --- with --yes: applies
        [$code, $stdout, $stderr] = CliProcess::run([...$command, '--yes', '--report=' . $directory . '/applied.json']);
        self::assertSame(0, $code, $stderr . $stdout);
        self::assertStringContainsString('Run finished.', $stdout);
        self::assertSame($newDomain, self::scalar($db, 'SELECT domain FROM ps_shop_url LIMIT 1'));
        self::assertSame($newDomain, self::scalar($db, 'SELECT domain_ssl FROM ps_shop_url LIMIT 1'));
        self::assertSame($newDomain, self::scalar($db, "SELECT value FROM ps_configuration WHERE name = 'PS_SHOP_DOMAIN'"));
        self::assertSame($newDomain, self::scalar($db, "SELECT value FROM ps_configuration WHERE name = 'PS_SHOP_DOMAIN_SSL'"));
        $applied = $this->report($directory . '/applied.json');
        self::assertFalse($applied['dryRun']);
        self::assertSame($dry['plannedChanges'], $applied['plannedChanges'], 'The dry run announced what the real run did.');
        self::assertGreaterThan(0, $applied['appliedChanges']);

        $journals = glob($directory . '/journal/threadsafe-journal-*.jsonl') ?: [];
        self::assertCount(1, $journals);
        self::assertSame(0600, fileperms($journals[0]) & 0777);
        self::assertSame(0700, fileperms($directory . '/journal') & 0777);
        self::assertGreaterThan(20, count(array_filter(explode("\n", (string) file_get_contents($journals[0])))), 'Every changed value is journalled.');

        // --- caches, skipped log table, source
        self::assertSame('0', self::scalar($db, 'SELECT COUNT(*) FROM ps_smarty_cache'));
        self::assertSame($logRowsBefore, self::scalar($db, 'SELECT COUNT(*) FROM ps_connections_source'));
        self::assertGreaterThanOrEqual(200000, (int) self::scalar($db, "SELECT COUNT(*) FROM ps_connections_source WHERE http_referer LIKE '%$oldDomain%'"));
        self::assertSame($oldDomain, self::scalar(Stack::sourceDb(), 'SELECT domain FROM ps_shop_url LIMIT 1'), 'The source database was not touched.');

        // --- the same command again finds nothing to do (the shop already uses the new domain)
        [$code, $stdout, $stderr] = CliProcess::run([...$command, '--yes', '--report=' . $directory . '/again.json']);
        self::assertSame(0, $code, $stderr . $stdout);
        self::assertStringContainsString('already uses', $stdout);
        $again = $this->report($directory . '/again.json');
        self::assertSame(0, $again['plannedChanges'], 'A repeated run must not change anything.');
        foreach ($again['steps'] as $step) {
            self::assertSame('unchanged', $step['status'], $step['id']);
        }
    }

    public function testWrongOldDomainIsRefused(): void
    {
        $directory = $this->tempDirectory();
        $command = $this->command($directory, 'also-elsewhere.test');

        // Naming a domain that is not the shop's (and a target the shop does not use): refused before anything is planned.
        [$code, , $stderr] = CliProcess::run([...$command, '--from=somewhere-else.test', '--yes']);

        self::assertSame(2, $code);
        self::assertStringContainsString('is not a domain of this shop', $stderr);
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
        // The fixture rule (tests/fixtures/rules): this webhook is registered elsewhere and kept, the bare name is replaced.
        self::assertSame('http://' . Stack::SOURCE_HOST . '/module/threadsafe_fixture/hook?token=abc', self::scalar($db, "SELECT value FROM ps_configuration WHERE name = 'THREADSAFE_FIXTURE_WEBHOOK'"));
        self::assertSame($new, self::scalar($db, "SELECT value FROM ps_configuration WHERE name = 'THREADSAFE_FIXTURE_BARE'"));
        self::assertSame(0, (int) self::scalar($db, "SELECT COUNT(*) FROM ps_threadsafe_fixture_item WHERE url LIKE '%" . Stack::SOURCE_HOST . "%'"));
        self::assertSame(0, (int) self::scalar($db, "SELECT COUNT(*) FROM ps_threadsafe_fixture_item_lang WHERE text LIKE '%http://" . Stack::SOURCE_HOST . "%'"));

        $serializedPayload = self::scalar($db, "SELECT payload FROM ps_threadsafe_fixture_item WHERE name = 'serialized'");
        self::assertSame(['url' => "http://$new/item/2", 'n' => 2, 'tags' => ['a', 'b']], unserialize($serializedPayload));
        self::assertStringContainsString('info@other.test', self::scalar($db, "SELECT payload FROM ps_threadsafe_fixture_item WHERE name = 'plain'"));
    }
}
