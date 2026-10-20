<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Core\Version;

final class VersionTest extends TestCase
{
    public function testCurrentVersionIsValid(): void
    {
        self::assertTrue(Version::isValid(Version::CURRENT));
    }

    #[DataProvider('versions')]
    public function testIsValid(string $version, bool $expected): void
    {
        self::assertSame($expected, Version::isValid($version));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function versions(): iterable
    {
        yield 'release' => ['1.2.3', true];
        yield 'pre-release' => ['0.0.0-dev', true];
        yield 'two parts' => ['1.2', false];
        yield 'leading text' => ['v1.2.3', false];
        yield 'empty' => ['', false];
    }
}
