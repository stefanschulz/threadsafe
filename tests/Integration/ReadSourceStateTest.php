<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\NoticeLevel;
use ThreadSafe\Core\NullJournal;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\PrestaShop9\State;
use ThreadSafe\PrestaShop9\Steps\ReadSourceState;
use ThreadSafe\Replace\DomainMapping;
use ThreadSafe\Tests\Support\ScratchShop;

final class ReadSourceStateTest extends IntegrationTestCase
{
    private ScratchShop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = ScratchShop::create('old.test');
    }

    protected function tearDown(): void
    {
        $this->shop->destroy();
    }

    private function plan(MigrationRequest $request): \ThreadSafe\Core\Plan
    {
        $step = new ReadSourceState();
        $context = $this->shop->context($request, new NullJournal(), dryRun: true);

        return $step->plan($context);
    }

    public function testReadsTheInstallation(): void
    {
        $request = new MigrationRequest($this->shop->root, [new DomainMapping('old.test', 'new.test')]);
        $context = $this->shop->context($request, new NullJournal(), dryRun: true);

        $plan = (new ReadSourceState())->plan($context);

        $source = State::source($context);
        self::assertSame('9.0.2', $source->version);
        self::assertSame('admin-x', $source->adminDirectory);
        self::assertSame('ps_', $source->prefix());
        self::assertCount(1, $source->shopUrls);
        self::assertSame('old.test', $source->shopUrls[0]->domain);
        self::assertTrue($source->shopUrls[0]->main);
        self::assertNotSame('', $source->serverVersion);
        self::assertFalse($plan->hasChanges(), 'Reading changes nothing.');
        self::assertSame(NoticeLevel::Info, $plan->notices()[0]->level);
        self::assertTrue(State::connection($context)->server->isSupported());
    }

    public function testRequestedDatabaseOverridesTheOneInParametersFile(): void
    {
        // parameters.php points to a database that does not exist, the request names the right one.
        $this->shop->writeFile('app/config/parameters.php', '<?php return ' . var_export(['parameters' => [
            'database_host' => 'db', 'database_port' => '3306', 'database_name' => 'does_not_exist',
            'database_user' => 'root', 'database_password' => 'x', 'database_prefix' => 'ps_',
        ]], true) . ';');
        $request = new MigrationRequest($this->shop->root, [new DomainMapping('old.test', 'new.test')], database: $this->shop->settings);
        $context = $this->shop->context($request, new NullJournal(), dryRun: true);

        (new ReadSourceState())->plan($context);

        self::assertSame($this->shop->settings->name, State::source($context)->database->name);
        self::assertSame('does_not_exist', State::source($context)->configuredDatabase->name);
    }

    public function testRestoreInPlaceNeedsNoMapping(): void
    {
        $plan = $this->plan(new MigrationRequest($this->shop->root));

        self::assertFalse($plan->hasChanges());
    }

    public function testAlreadyMigratedShopIsAcceptedWithANote(): void
    {
        $this->shop->exec("UPDATE ps_shop_url SET domain = 'new.test', domain_ssl = 'new.test'");

        $plan = $this->plan(new MigrationRequest($this->shop->root, [new DomainMapping('old.test', 'new.test')]));

        self::assertStringContainsString('already use "new.test"', $plan->notices()[0]->message);
    }

    public function testUnmappedShopUrlIsAWarning(): void
    {
        $this->shop->exec("INSERT INTO ps_shop_url (id_shop, domain, domain_ssl, physical_uri, virtual_uri, main, active) VALUES (2, 'other.test', 'other.test', '/', '', 0, 1)");

        $plan = $this->plan(new MigrationRequest($this->shop->root, [new DomainMapping('old.test', 'new.test')]));

        $warnings = array_filter($plan->notices(), static fn ($n): bool => $n->level === NoticeLevel::Warning);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('other.test', array_values($warnings)[0]->message);
    }

    /**
     * @return iterable<string, array{callable(ScratchShop): MigrationRequest, string}>
     */
    public static function refusals(): iterable
    {
        yield 'domain is not the shop domain' => [
            static fn (ScratchShop $s): MigrationRequest => new MigrationRequest($s->root, [new DomainMapping('wrong.test', 'new.test')]),
            'not a domain of this shop',
        ];
        yield 'root is not a directory' => [
            static fn (ScratchShop $s): MigrationRequest => new MigrationRequest($s->root . '/nope'),
            'not a directory',
        ];
        yield 'database unreachable' => [
            static fn (ScratchShop $s): MigrationRequest => new MigrationRequest($s->root, database: new DatabaseSettings('db', 3306, 'x', 'root', 'wrong-password', 'ps_')),
            'Cannot connect',
        ];
        yield 'wrong prefix' => [
            static fn (ScratchShop $s): MigrationRequest => new MigrationRequest($s->root, database: new DatabaseSettings('db', 3306, $s->settings->name, 'root', $s->settings->password, 'xx_')),
            'wrong table prefix',
        ];
    }

    /**
     * @param callable(ScratchShop): MigrationRequest $request
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testRefusesTheRun(callable $request, string $messagePart): void
    {
        $this->expectException(GuardFailed::class);
        $this->expectExceptionMessage($messagePart);

        $this->plan($request($this->shop));
    }

    public function testRefusesAnOtherMajorVersion(): void
    {
        $this->shop->writeFile('src/Core/Version.php', "<?php\nfinal class Version { public const VERSION = '8.2.3'; }\n");

        $this->expectException(GuardFailed::class);
        $this->expectExceptionMessage('Only PrestaShop 9');

        $this->plan(new MigrationRequest($this->shop->root));
    }

    public function testRefusesAFolderThatIsNotPrestaShop(): void
    {
        unlink($this->shop->root . '/src/Core/Version.php');

        $this->expectException(GuardFailed::class);
        $this->expectExceptionMessage('does not look like a PrestaShop 9 installation');

        $this->plan(new MigrationRequest($this->shop->root));
    }

    public function testRefusesAnUnreadableParametersFile(): void
    {
        $this->shop->writeFile('app/config/parameters.php', "<?php return ['parameters' => ['database_host' => getenv('X')]];");

        $this->expectException(GuardFailed::class);
        $this->expectExceptionMessage('Cannot read the database settings');

        $this->plan(new MigrationRequest($this->shop->root));
    }

    public function testRefusesAShopWithoutShopUrl(): void
    {
        $this->shop->exec('DELETE FROM ps_shop_url');

        $this->expectException(GuardFailed::class);
        $this->expectExceptionMessage('has no shop URL');

        $this->plan(new MigrationRequest($this->shop->root));
    }
}
