<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Migration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Migration\MailCatcher;

final class MailCatcherTest extends TestCase
{
    public function testHostAndPort(): void
    {
        $catcher = MailCatcher::parse('mailpit:2525');

        self::assertSame('mailpit', $catcher->host);
        self::assertSame(2525, $catcher->port);
        self::assertSame('mailpit:2525', (string) $catcher);
    }

    public function testHostAloneUsesTheUsualCatcherPort(): void
    {
        self::assertSame(1025, MailCatcher::parse('localhost')->port);
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidValues(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        MailCatcher::parse($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'port is not a number' => ['mail:smtp'];
        yield 'port zero' => ['mail:0'];
        yield 'port too large' => ['mail:70000'];
        yield 'url' => ['smtp://mail'];
        yield 'host with a space' => ['my host'];
        yield 'host with a quote' => ["mail'host"];
        yield 'host starts with a dot' => ['.mail'];
    }
}
