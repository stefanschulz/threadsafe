<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Restore;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Backup\Manifest;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Restore\Check;
use ThreadSafe\Restore\Field;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * What the two website types say about a restore: the fields they ask for, the settings those become, the checks.
 */
final class RestorableTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @param array<string, mixed> $extras
     */
    private function manifest(string $type = 'prestashop9', ?string $version = '9.0.2', array $extras = ['shopUrls' => [['domain' => 'second.example', 'physicalUri' => '/x/', 'main' => false], ['domain' => 'shop.example', 'physicalUri' => '/shop/', 'main' => true]]]): Manifest
    {
        $utc = new DateTimeZone('UTC');

        return new Manifest(
            'shop_20261020-123456_ab12cd34',
            '0.0.0-dev',
            'shop',
            'Webshop',
            $type,
            ['name' => 'PrestaShop', 'version' => $version],
            '8.4.2',
            ['server' => 'mariadb', 'version' => '10.11.6', 'driver' => 'pdo', 'name' => 'shop', 'prefix' => 'ps_', 'charset' => 'utf8mb4', 'collations' => []],
            'snapshot',
            $extras,
            ['excludePaths' => [], 'structureOnlyTables' => [], 'literalExcludes' => []],
            ['files' => 1, 'directories' => 1, 'fileBytes' => 1, 'tables' => 1, 'rows' => 1, 'databaseBytes' => 1],
            [['file' => 'shop_20261020-123456_ab12cd34.zip', 'entries' => 1]],
            new DateTimeImmutable('2026-10-20 12:34:56', $utc),
            new DateTimeImmutable('2026-10-20 12:40:00', $utc),
        );
    }

    public function testPrestaShopAsksForTheNewAddressAndKnowsTheOldDomain(): void
    {
        $type = PluginRegistry::load()->restorable('prestashop9');
        $manifest = $this->manifest();

        $fields = array_column($type->fields($manifest), null, 'name');

        self::assertSame(['to', 'path', 'ssl', 'mode', 'mailCatcher'], array_keys($fields));
        self::assertTrue($fields['to']->required);
        self::assertStringContainsString('shop.example', $fields['to']->help, 'the main shop URL, not the first');
        self::assertSame(Field::CHOICE, $fields['ssl']->kind);
        self::assertSame(['', 'none', 'login', 'all'], $fields['ssl']->choices);
        self::assertSame('shop.example', $type->sourceDomain($manifest));
    }

    public function testTheTypesNameTheSiteAndTheLoginOfTheAdministration(): void
    {
        $registry = PluginRegistry::load();
        $root = $this->tempDirectory();
        mkdir($root . '/admin42abc/themes/new-theme', 0777, true);

        $shop = $registry->restorable('prestashop9');
        self::assertSame(
            [['label' => 'Open the shop', 'path' => '/shop/'], ['label' => 'Back office login', 'path' => '/shop/admin42abc/']],
            $shop->links($this->manifest(), $root, [], '/'),
            'an empty base path keeps the one of the backup',
        );
        self::assertSame('/new/', $shop->links($this->manifest(), $root, ['path' => 'new'], '/')[0]['path']);
        self::assertCount(1, $shop->links($this->manifest(), $root . '/missing', [], '/'), 'no back office folder found: only the shop');

        $joomla = $registry->restorable('joomla');
        self::assertSame(
            [['label' => 'Open the site', 'path' => '/sub/'], ['label' => 'Administration login', 'path' => '/sub/administrator/']],
            $joomla->links($this->manifest('joomla', '5.2.0', []), $root, [], '/sub/'),
            'no base path typed: where the wizard is served from',
        );
        self::assertSame('/blog/administrator/', $joomla->links($this->manifest('joomla', '5.2.0', []), $root, ['path' => '/blog'], '/')[1]['path']);

        self::assertSame([['label' => 'Open the site', 'path' => '/']], $registry->restorable('generic')->links($this->manifest('generic', null, []), $root, [], '/'));
    }

    public function testPrestaShopAnswersBecomeSettingsAndEmptyAnswersAreLeftOut(): void
    {
        $type = PluginRegistry::load()->restorable('prestashop9');

        self::assertEqualsCanonicalizing(
            ['to' => 'new.example', 'ssl' => 'all', 'mode' => 'dev', 'mailCatcher' => 'mail:1025'],
            $type->restoreSettings(['to' => 'new.example', 'path' => '', 'ssl' => 'all', 'mode' => 'dev', 'mailCatcher' => 'mail:1025']),
        );
        self::assertSame(['to' => 'new.example'], $type->restoreSettings(['to' => 'new.example', 'path' => '', 'ssl' => '', 'mode' => 'production', 'mailCatcher' => '']));
    }

    public function testPrestaShopChecks(): void
    {
        $type = PluginRegistry::load()->restorable('prestashop9');

        self::assertSame([], $type->restoreChecks($this->manifest()));

        $old = $type->restoreChecks($this->manifest(version: '8.1.0'));
        self::assertCount(1, $old);
        self::assertTrue($old[0]->isError());
        self::assertStringContainsString('only PrestaShop 9', $old[0]->message);

        $unknown = $type->restoreChecks($this->manifest(version: null, extras: []));
        self::assertSame([Check::ERROR, Check::WARNING], array_map(static fn (Check $c): string => $c->level, $unknown));

        $partial = $type->restoreChecks($this->manifest(extras: ['shopUrls' => [['domain' => 'a.example', 'main' => true]], 'extrasWarning' => 'database was busy']));
        self::assertSame(Check::WARNING, $partial[0]->level);
        self::assertStringContainsString('database was busy', $partial[0]->message);
    }

    public function testGenericAsksOnlyForTheDomains(): void
    {
        $type = PluginRegistry::load()->restorable('generic');
        $manifest = $this->manifest('generic');

        self::assertSame(['from', 'to'], array_map(static fn (Field $f): string => $f->name, $type->fields($manifest)));
        self::assertSame(['from' => 'old.example', 'to' => 'new.example'], $type->restoreSettings(['from' => 'old.example', 'to' => 'new.example']));
        self::assertSame([], $type->restoreSettings(['from' => '', 'to' => '']));
        self::assertNull($type->sourceDomain($manifest));
        self::assertSame([], $type->restoreChecks($manifest));
    }
}
