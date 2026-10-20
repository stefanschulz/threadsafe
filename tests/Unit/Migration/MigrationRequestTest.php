<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Migration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Core\Resources;
use ThreadSafe\Migration\Mode;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\Migration\SslMode;
use ThreadSafe\Replace\DomainMapping;

final class MigrationRequestTest extends TestCase
{
    public function testDefaults(): void
    {
        $request = new MigrationRequest('/var/www/html');

        self::assertTrue($request->isRestoreInPlace());
        self::assertNull($request->urlMatcher());
        self::assertSame(Mode::Production, $request->mode);
        self::assertNull($request->ssl);
        self::assertNull($request->physicalUri);
        self::assertFalse($request->replaceBare);
    }

    public function testMappingsAreLookedUpCaseInsensitively(): void
    {
        $request = new MigrationRequest('/shop', [new DomainMapping('Old.Test', 'new.test')]);

        self::assertFalse($request->isRestoreInPlace());
        self::assertNotNull($request->urlMatcher());
        self::assertSame('new.test', $request->mappingFrom('OLD.test')?->to);
        self::assertNull($request->mappingFrom('other.test'));
        self::assertTrue($request->isTargetDomain('NEW.TEST'));
        self::assertFalse($request->isTargetDomain('old.test'));
    }

    public function testRejectsTheSameDomainMappedTwice(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MigrationRequest('/shop', [new DomainMapping('a.test', 'b.test'), new DomainMapping('A.test', 'c.test')]);
    }

    #[DataProvider('validPaths')]
    public function testAcceptsValidBasePaths(string $path): void
    {
        self::assertSame($path, (new MigrationRequest('/shop', physicalUri: $path))->physicalUri);
    }

    /** @return iterable<string, array{string}> */
    public static function validPaths(): iterable
    {
        yield 'root' => ['/'];
        yield 'one level' => ['/shop/'];
        yield 'two levels' => ['/de/shop/'];
        yield 'dots and dashes' => ['/my-shop_2.0/'];
    }

    #[DataProvider('invalidPaths')]
    public function testRejectsInvalidBasePaths(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MigrationRequest('/shop', physicalUri: $path);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPaths(): iterable
    {
        yield 'no leading slash' => ['shop/'];
        yield 'no trailing slash' => ['/shop'];
        yield 'empty' => [''];
        yield 'double slash' => ['/a//b/'];
        yield 'query' => ['/shop/?x=1'];
        yield 'space' => ['/my shop/'];
    }

    public function testRejectsAnEmptyRootAndBadChunkSizes(): void
    {
        foreach ([[''], ['/shop', [], null, null, null, Mode::Production, [], false, 0], ['/shop', [], null, null, null, Mode::Production, [], false, 100001]] as $arguments) {
            try {
                new MigrationRequest(...$arguments);
                self::fail('Invalid request accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSslModes(): void
    {
        self::assertFalse(SslMode::None->sslEnabled());
        self::assertTrue(SslMode::Login->sslEnabled());
        self::assertFalse(SslMode::Login->everywhere());
        self::assertTrue(SslMode::All->everywhere());
    }

    public function testShippedResourceListsAreComplete(): void
    {
        $skip = Resources::strings('prestashop9/skip-tables.json', 'tables');
        foreach (['connections', 'connections_source', 'guest', 'log', 'smarty_cache'] as $table) {
            self::assertContains($table, $skip);
        }

        $caches = Resources::json('prestashop9/caches.json');
        self::assertContains('var/cache', $caches['directories']);
        self::assertContains('CACHEDIR.TAG', $caches['keep']);
        self::assertContains('smarty_lazy_cache', $caches['tables']);
    }

    public function testMissingResourceIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);

        Resources::json('prestashop9/does-not-exist.json');
    }
}
