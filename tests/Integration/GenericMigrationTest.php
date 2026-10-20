<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use ThreadSafe\Tests\Support\CliProcess;
use ThreadSafe\Tests\Support\Stack;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The website type "generic" run as a real process on the migrated clone: it knows nothing about PrestaShop and still
 * replaces the domain in every table. Runs in the third phase of tests/stack/run.sh, after everything that needs the
 * shop to be working, because it breaks the shop's domain on purpose.
 */
#[Group('dev-migration')]
final class GenericMigrationTest extends IntegrationTestCase
{
    use TempDirectory;

    private const string NEW_DOMAIN = 'generic.example.test';

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @return list<string>
     */
    private function command(string $directory): array
    {
        if (!is_dir('/shops/target/src/Core')) {
            self::markTestSkipped('The target volume is not mounted at /shops/target.');
        }
        file_put_contents($directory . '/db-password', "target\n");

        return [
            'migrate', 'generic',
            '--root=/shops/target',
            '--db-host=target-db', '--db-port=3306', '--db-name=prestashop_target', '--db-user=target',
            '--db-password-file=' . $directory . '/db-password',
            '--journal-dir=' . $directory . '/journal',
            // Tables that only record visits: a generic site has no list of them.
            '--skip-tables=ps_connections,ps_connections_source,ps_connections_page,ps_guest,ps_log,ps_pagenotfound,ps_page_viewed,ps_statssearch',
        ];
    }

    public function testNeedsTheOldDomain(): void
    {
        $directory = $this->tempDirectory();

        [$code, , $stderr] = CliProcess::run([...$this->command($directory), '--to=' . self::NEW_DOMAIN]);

        self::assertSame(2, $code);
        self::assertStringContainsString('give the old domain with --from', $stderr);
    }

    public function testReplacesTheDomainInEveryTableWithoutKnowingTheApplication(): void
    {
        $directory = $this->tempDirectory();
        $db = Stack::targetDb();
        $old = Stack::TARGET_HOST;
        $command = [...$this->command($directory), '--from=' . $old, '--to=' . self::NEW_DOMAIN];

        // --- dry run
        [$code, $stdout, $stderr] = CliProcess::run([...$command, '--no-self-test', '--report=' . $directory . '/dry.json']);
        self::assertSame(0, $code, $stderr . $stdout);
        self::assertStringContainsString('Dry run – nothing was changed.', $stdout);
        $dry = json_decode((string) file_get_contents($directory . '/dry.json'), true);
        self::assertIsArray($dry);
        self::assertSame(['read-source-state', 'replace-content-domains', 'self-test'], array_column($dry['steps'], 'id'));
        self::assertGreaterThan(10, $dry['plannedChanges']);
        self::assertGreaterThan(0, (int) self::scalar($db, "SELECT COUNT(*) FROM ps_cms_lang WHERE content LIKE '%$old%'"));

        // --- applied
        [$code, $stdout, $stderr] = CliProcess::run([...$command, '--no-self-test', '--yes', '--db-driver=mysqli']);
        self::assertSame(0, $code, $stderr . $stdout);
        self::assertSame(0, (int) self::scalar($db, "SELECT COUNT(*) FROM ps_cms_lang WHERE content LIKE '%http://$old/%' OR content LIKE '%https://$old/%'"));
        self::assertGreaterThan(0, (int) self::scalar($db, "SELECT COUNT(*) FROM ps_cms_lang WHERE content LIKE '%http://" . self::NEW_DOMAIN . "/%'"));

        $serialized = unserialize(self::scalar($db, "SELECT value FROM ps_configuration WHERE name = 'THREADSAFE_FIXTURE_SERIALIZED'"), ['allowed_classes' => [\stdClass::class]]);
        self::assertIsArray($serialized);
        self::assertSame('http://' . self::NEW_DOMAIN . '/', $serialized['home'], 'Serialized values stay valid.');
        self::assertSame(Stack::SOURCE_HOST, $serialized['meta']['bare'], 'A bare host name is not a URL.');
        self::assertSame($old, self::scalar($db, 'SELECT domain FROM ps_shop_url LIMIT 1'), 'The shop URL is a bare domain: the generic type leaves it.');
        self::assertGreaterThan(0, (int) self::scalar($db, "SELECT COUNT(*) FROM ps_connections_source WHERE http_referer LIKE '%" . Stack::SOURCE_HOST . "%'"), 'Skipped tables stay as they are (the seeded rows carry the source domain).');

        // --- and again: nothing left
        [$code, $stdout, $stderr] = CliProcess::run([...$command, '--no-self-test', '--yes', '--report=' . $directory . '/again.json']);
        self::assertSame(0, $code, $stderr . $stdout);
        $again = json_decode((string) file_get_contents($directory . '/again.json'), true);
        self::assertIsArray($again);
        self::assertSame(0, $again['plannedChanges'], 'A repeated run changes nothing.');
    }
}
