<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use ThreadSafe\Core\Context;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\FileJournal;
use ThreadSafe\Core\NoticeLevel;
use ThreadSafe\Core\NullJournal;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\Migration\SslMode;
use ThreadSafe\PrestaShop9\Steps\ReadSourceState;
use ThreadSafe\PrestaShop9\Steps\UpdateConfiguration;
use ThreadSafe\Replace\DomainMapping;
use ThreadSafe\Tests\Support\ScratchShop;
use ThreadSafe\Tests\Support\TempDirectory;

final class UpdateConfigurationTest extends IntegrationTestCase
{
    use TempDirectory;

    private ScratchShop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = ScratchShop::create('old.test');
    }

    protected function tearDown(): void
    {
        $this->shop->destroy();
        $this->cleanUpTempDirectories();
    }

    private function context(MigrationRequest $request, ?FileJournal $journal = null): Context
    {
        $context = $this->shop->context($request, $journal ?? new NullJournal(), $journal === null);
        (new ReadSourceState())->plan($context);

        return $context;
    }

    private function apply(MigrationRequest $request, ?FileJournal $journal = null): int
    {
        $context = $this->context($request, $journal ?? new FileJournal($this->tempDirectory()));
        $step = new UpdateConfiguration();

        return $step->apply($context, $step->plan($context))->applied;
    }

    private function request(?string $physicalUri = null, ?SslMode $ssl = null, string $to = 'new.test'): MigrationRequest
    {
        return new MigrationRequest($this->shop->root, [new DomainMapping('old.test', $to)], physicalUri: $physicalUri, ssl: $ssl);
    }

    public function testPlanListsEveryValueThatChanges(): void
    {
        $plan = (new UpdateConfiguration())->plan($this->context($this->request('/shop/', SslMode::All)));

        $targets = array_map(static fn ($c): string => $c->target . ' → ' . $c->after, $plan->changes());
        self::assertContains('db ps_configuration PS_SHOP_DOMAIN → new.test', $targets);
        self::assertContains('db ps_configuration PS_SHOP_DOMAIN_SSL → new.test', $targets);
        self::assertContains('db ps_configuration PS_SSL_ENABLED → 1', $targets);
        self::assertContains('db ps_configuration PS_SSL_ENABLED_EVERYWHERE → 1', $targets);
        self::assertContains('db ps_shop_url #1 domain → new.test', $targets);
        self::assertContains('db ps_shop_url #1 domain_ssl → new.test', $targets);
        self::assertContains('db ps_shop_url #1 physical_uri → /shop/', $targets);
    }

    public function testDryRunChangesNothing(): void
    {
        (new UpdateConfiguration())->plan($this->context($this->request('/shop/', SslMode::All)));

        self::assertSame('old.test', $this->shop->value("SELECT value FROM ps_configuration WHERE name = 'PS_SHOP_DOMAIN'"));
        self::assertSame('old.test', $this->shop->value('SELECT domain FROM ps_shop_url'));
    }

    public function testApplyUpdatesConfigurationAndShopUrl(): void
    {
        $applied = $this->apply($this->request('/shop/', SslMode::All));

        self::assertSame(7, $applied);
        self::assertSame('new.test', $this->shop->value("SELECT value FROM ps_configuration WHERE name = 'PS_SHOP_DOMAIN'"));
        self::assertSame('new.test', $this->shop->value("SELECT value FROM ps_configuration WHERE name = 'PS_SHOP_DOMAIN_SSL'"));
        self::assertSame('1', $this->shop->value("SELECT value FROM ps_configuration WHERE name = 'PS_SSL_ENABLED'"));
        self::assertSame('1', $this->shop->value("SELECT value FROM ps_configuration WHERE name = 'PS_SSL_ENABLED_EVERYWHERE'"), 'A missing setting is created.');
        self::assertSame(['new.test', 'new.test', '/shop/'], array_values($this->shop->rows('SELECT domain, domain_ssl, physical_uri FROM ps_shop_url')[0]));
    }

    public function testSslModes(): void
    {
        $this->apply($this->request(ssl: SslMode::Login));
        self::assertSame('1', $this->shop->value("SELECT value FROM ps_configuration WHERE name = 'PS_SSL_ENABLED'"));
        self::assertSame('0', $this->shop->value("SELECT value FROM ps_configuration WHERE name = 'PS_SSL_ENABLED_EVERYWHERE'"));

        $this->apply($this->request(ssl: SslMode::None, to: 'new.test'));
        self::assertSame('0', $this->shop->value("SELECT value FROM ps_configuration WHERE name = 'PS_SSL_ENABLED'"));
    }

    public function testUnrequestedSslSettingIsLeftAlone(): void
    {
        $this->apply($this->request());

        self::assertSame('0', $this->shop->value("SELECT value FROM ps_configuration WHERE name = 'PS_SSL_ENABLED'"));
        self::assertSame('0', $this->shop->value("SELECT COUNT(*) FROM ps_configuration WHERE name = 'PS_SSL_ENABLED_EVERYWHERE'"));
    }

    public function testSecondRunHasNothingToDo(): void
    {
        $this->apply($this->request('/shop/', SslMode::All));

        $plan = (new UpdateConfiguration())->plan($this->context($this->request('/shop/', SslMode::All)));

        self::assertFalse($plan->hasChanges());
    }

    public function testEveryChangedValueIsJournalledWithOldAndNew(): void
    {
        $journal = new FileJournal($this->tempDirectory());

        $this->apply($this->request(), $journal);

        $entries = array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_filter(explode("\n", (string) file_get_contents((string) $journal->path()))),
        );
        $changes = array_values(array_filter($entries, static fn (array $e): bool => $e['type'] === 'db'));
        self::assertCount(4, $changes);
        $domain = array_values(array_filter($changes, static fn (array $e): bool => $e['table'] === 'ps_shop_url' && $e['column'] === 'domain'))[0];
        self::assertSame('old.test', $domain['old']);
        self::assertSame('new.test', $domain['new']);
        self::assertSame(['id_shop_url' => 1], $domain['key']);
    }

    public function testMediaServerOfTheOldDomainIsReplaced(): void
    {
        $this->shop->exec("INSERT INTO ps_configuration (name, value, date_add, date_upd) VALUES ('PS_MEDIA_SERVER_1', 'old.test', NOW(), NOW())");

        $this->apply($this->request());

        self::assertSame('new.test', $this->shop->value("SELECT value FROM ps_configuration WHERE name = 'PS_MEDIA_SERVER_1'"));
    }

    public function testUnmappedDomainValueIsKeptWithAWarning(): void
    {
        $this->shop->exec("INSERT INTO ps_configuration (name, value, date_add, date_upd) VALUES ('PS_MEDIA_SERVER_2', 'cdn.other.test', NOW(), NOW())");

        $plan = (new UpdateConfiguration())->plan($this->context($this->request()));

        $warnings = array_filter($plan->notices(), static fn ($n): bool => $n->level === NoticeLevel::Warning);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('cdn.other.test', array_values($warnings)[0]->message);
        $this->apply($this->request());
        self::assertSame('cdn.other.test', $this->shop->value("SELECT value FROM ps_configuration WHERE name = 'PS_MEDIA_SERVER_2'"));
    }

    public function testRestoreInPlaceWithoutOtherRequestsDoesNothing(): void
    {
        $plan = (new UpdateConfiguration())->plan($this->context(new MigrationRequest($this->shop->root)));

        self::assertFalse($plan->hasChanges());
        self::assertNotEmpty($plan->notices());
    }

    public function testPathCanBeChangedWithoutChangingTheDomain(): void
    {
        $this->apply(new MigrationRequest($this->shop->root, physicalUri: '/new/'));

        self::assertSame('/new/', $this->shop->value('SELECT physical_uri FROM ps_shop_url'));
        self::assertSame('old.test', $this->shop->value('SELECT domain FROM ps_shop_url'));
    }

    public function testAmbiguousMappingIsRefused(): void
    {
        $this->shop->exec("INSERT INTO ps_shop_url (id_shop, domain, domain_ssl, physical_uri, virtual_uri, main, active) VALUES (2, 'second.test', 'second.test', '/', '', 0, 1)");
        $request = new MigrationRequest($this->shop->root, [new DomainMapping('old.test', 'new.test'), new DomainMapping('second.test', 'new.test')]);

        $this->expectException(GuardFailed::class);
        $this->expectExceptionMessage('ambiguous');

        (new UpdateConfiguration())->plan($this->context($request));
    }

    public function testTwoShopsAreMappedIndependently(): void
    {
        $this->shop->exec("INSERT INTO ps_shop_url (id_shop, domain, domain_ssl, physical_uri, virtual_uri, main, active) VALUES (2, 'second.test', 'second.test', '/', '', 0, 1)");
        $request = new MigrationRequest($this->shop->root, [new DomainMapping('old.test', 'new.test'), new DomainMapping('second.test', 'second.example')]);

        $this->apply($request);

        self::assertSame(['new.test', 'second.example'], array_column($this->shop->rows('SELECT domain FROM ps_shop_url ORDER BY id_shop_url'), 'domain'));
    }
}
