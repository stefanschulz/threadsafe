<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PDO;
use ThreadSafe\Database\Connection;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\CheckLevel;
use ThreadSafe\Profile\ConnectionTest;
use ThreadSafe\Profile\ProfileResolver;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Profile\TestReport;
use ThreadSafe\Tests\Support\ScratchShop;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The connection test and the PrestaShop 9 backup side against a database server: a scratch shop (files and database),
 * database users with fewer privileges, a wrong password.
 */
final class ProfileConnectionTest extends IntegrationTestCase
{
    use TempDirectory;

    private ScratchShop $shop;

    /** @var list<string> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = ScratchShop::create();
    }

    protected function tearDown(): void
    {
        if (isset($this->shop)) {
            foreach ($this->users as $user) {
                $this->shop->pdo()->exec("DROP USER IF EXISTS '$user'@'%'");
            }
            $this->shop->destroy();
        }
        $this->cleanUpTempDirectories();
    }

    /** @param array<string, mixed> $changes */
    private function profile(array $changes = []): SiteProfile
    {
        return SiteProfile::fromArray([
            'id' => 'scratch',
            'type' => 'prestashop9',
            'path' => $this->shop->root,
            'storage' => $this->tempDirectory() . '/storage',
            ...$changes,
        ]);
    }

    private function test(SiteProfile $profile): TestReport
    {
        return (new ConnectionTest(PluginRegistry::load(), $this->tempDirectory() . '/data'))->run($profile);
    }

    private static function text(TestReport $report): string
    {
        return implode("\n", array_map(static fn ($c): string => $c->level->value . ' ' . $c->subject . ': ' . $c->message, $report->checks));
    }

    public function testAHealthyShopPasses(): void
    {
        $report = $this->test($this->profile());
        $text = self::text($report);

        self::assertTrue($report->passed(), $text);
        self::assertStringContainsString('ok Site: PrestaShop 9.0.2', $text);
        self::assertStringContainsString("ok Database: \"{$this->shop->settings->name}\"", $text);
        self::assertStringContainsString("from the site's configuration", $text);
        self::assertMatchesRegularExpression('/ok Database server: (MariaDB|MySQL) \d/', $text);
        self::assertStringContainsString('ok Privileges: SELECT, SHOW VIEW, TRIGGER', $text);
        self::assertStringContainsString('ok Snapshot:', $text);
        self::assertMatchesRegularExpression('/info Database size: 8 tables/', $text);
        self::assertStringContainsString('info max_allowed_packet:', $text);
        self::assertSame([], $report->messages(CheckLevel::Warning), 'A healthy setup has no warning: ' . $text);
    }

    public function testRoutinesAreReportedAsNotBackedUp(): void
    {
        $this->shop->pdo()->exec('CREATE FUNCTION f_one() RETURNS INT DETERMINISTIC RETURN 1');

        $report = $this->test($this->profile());

        self::assertTrue($report->passed());
        self::assertStringContainsString('function f_one', implode("\n", $report->messages(CheckLevel::Warning)));
    }

    public function testAUserWithoutViewAndTriggerPrivilegesGetsAWarning(): void
    {
        $this->createUser('ts_select', 'SELECT');

        $report = $this->test($this->profile(['database' => $this->database('ts_select')]));

        self::assertTrue($report->passed(), self::text($report));
        self::assertStringContainsString('lacks SHOW VIEW, TRIGGER', implode("\n", $report->messages(CheckLevel::Warning)));
        self::assertStringContainsString('from the profile', self::text($report));
    }

    public function testAUserWithoutSelectFails(): void
    {
        $this->createUser('ts_nothing', 'SHOW VIEW');

        $report = $this->test($this->profile(['database' => $this->database('ts_nothing')]));

        self::assertFalse($report->passed());
        self::assertStringContainsString('lacks SELECT', implode("\n", $report->messages(CheckLevel::Failure)));
    }

    public function testAWrongPasswordIsAFailureWithoutTheSecret(): void
    {
        $report = $this->test($this->profile(['database' => [...$this->database('root'), 'password' => 'definitely-wrong-123']]));

        self::assertFalse($report->passed());
        self::assertStringContainsString('Cannot connect to database', implode("\n", $report->messages(CheckLevel::Failure)));
        self::assertStringNotContainsString('definitely-wrong-123', self::text($report));
    }

    public function testManifestExtrasOfAPrestaShop(): void
    {
        $this->shop->pdo()->exec('CREATE TABLE ps_module (id_module INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NOT NULL, active TINYINT NOT NULL, version VARCHAR(8) NOT NULL)');
        $this->shop->exec("INSERT INTO ps_module (name, active, version) VALUES ('ps_emailsubscription', 1, '3.0.1'), ('blockreassurance', 0, '5.1.0')");
        $resolved = (new ProfileResolver(PluginRegistry::load(), $this->tempDirectory() . '/data'))->resolve($this->profile());

        $extras = $resolved->type->manifestExtras($resolved->site, Connection::open($this->shop->settings));

        self::assertSame('admin-x', $extras['adminDirectory']);
        self::assertSame('old.test', $extras['shopUrls'][0]['domain']);
        self::assertTrue($extras['shopUrls'][0]['main']);
        self::assertSame(['PS_SHOP_DOMAIN' => 'old.test', 'PS_SHOP_DOMAIN_SSL' => 'old.test', 'PS_SSL_ENABLED' => '0'], $extras['configuration']);
        self::assertSame([['name' => 'blockreassurance', 'version' => '5.1.0', 'active' => false], ['name' => 'ps_emailsubscription', 'version' => '3.0.1', 'active' => true]], $extras['modules']);
        self::assertArrayNotHasKey('extrasWarning', $extras);
        self::assertStringNotContainsString($this->shop->settings->password, (string) json_encode($extras), 'No secret goes into the manifest.');
    }

    public function testManifestExtrasDoNotFailWhenTheDatabaseIsBroken(): void
    {
        $resolved = (new ProfileResolver(PluginRegistry::load(), $this->tempDirectory() . '/data'))->resolve($this->profile());

        $withoutModules = $resolved->type->manifestExtras($resolved->site, Connection::open($this->shop->settings));
        $withoutDatabase = $resolved->type->manifestExtras($resolved->site, null);

        self::assertStringContainsString('Could not read the shop data', (string) $withoutModules['extrasWarning'], 'The scratch shop has no module table.');
        self::assertSame(['adminDirectory' => 'admin-x'], $withoutDatabase);
    }

    /**
     * @return array<string, mixed>
     */
    private function database(string $user): array
    {
        $s = $this->shop->settings;

        return ['host' => $s->host, 'port' => $s->port, 'name' => $s->name, 'user' => $user, 'password' => $user === 'root' ? $s->password : 'limited-pw', 'prefix' => $s->prefix];
    }

    private function createUser(string $user, string $privileges): void
    {
        $pdo = $this->shop->pdo();
        $pdo->exec("CREATE USER '$user'@'%' IDENTIFIED BY 'limited-pw'");
        $this->users[] = $user;
        $pdo->exec("GRANT $privileges ON `{$this->shop->settings->name}`.* TO '$user'@'%'");
        self::assertInstanceOf(PDO::class, $pdo);
    }
}
