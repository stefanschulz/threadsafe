<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Replace;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Replace\DomainMapping;

final class DomainMappingTest extends TestCase
{
    public function testNormalisesToLowerCase(): void
    {
        $mapping = new DomainMapping(' Shop.Example.COM ', 'LOCALHOST:8190');

        self::assertSame('shop.example.com', $mapping->from);
        self::assertSame('localhost:8190', $mapping->to);
        self::assertSame('shop.example.com', $mapping->fromHost());
        self::assertFalse($mapping->fromHasPort());
    }

    public function testPortIsPartOfTheName(): void
    {
        $mapping = new DomainMapping('localhost:8090', 'localhost:8190');

        self::assertSame('localhost', $mapping->fromHost());
        self::assertTrue($mapping->fromHasPort());
    }

    #[DataProvider('invalidDomains')]
    public function testRejectsInvalidDomains(string $from, string $to): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DomainMapping($from, $to);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidDomains(): iterable
    {
        yield 'with scheme' => ['https://shop.test', 'other.test'];
        yield 'with path' => ['shop.test/shop', 'other.test'];
        yield 'empty' => ['', 'other.test'];
        yield 'unicode' => ['bücher.test', 'other.test'];
        yield 'leading dot' => ['.shop.test', 'other.test'];
        yield 'port too long' => ['shop.test:123456', 'other.test'];
        yield 'same domain' => ['shop.test', 'SHOP.test'];
    }

    public function testRejectsInvalidScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DomainMapping('a.test', 'b.test', 'ftp');
    }
}
