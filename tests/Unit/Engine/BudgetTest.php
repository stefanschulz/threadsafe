<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Engine\Budget;

final class BudgetTest extends TestCase
{
    public function testTimeRunsOutAtTheLimit(): void
    {
        $now = 100.0;
        $budget = new Budget(2.0, null, 0.8, static function () use (&$now): float {
            return $now;
        });

        self::assertFalse($budget->exhausted());
        $now = 101.9;
        self::assertFalse($budget->exhausted());
        self::assertEqualsWithDelta(1.9, $budget->elapsed(), 0.0001);
        $now = 102.0;
        self::assertTrue($budget->exhausted());
    }

    public function testStartRestartsTheClock(): void
    {
        $now = 0.0;
        $budget = new Budget(1.0, null, 0.8, static function () use (&$now): float {
            return $now;
        });
        $now = 5.0;
        self::assertTrue($budget->exhausted());

        $budget->start();

        self::assertFalse($budget->exhausted());
        self::assertSame(0.0, $budget->elapsed());
    }

    public function testNoTimeLimitMeansNeverExhaustedByTime(): void
    {
        $now = 0.0;
        $budget = new Budget(null, null, 0.8, static function () use (&$now): float {
            return $now;
        });
        $now = 1e9;

        self::assertFalse($budget->exhausted());
    }

    public function testMemoryRunsOutAtAShareOfTheLimit(): void
    {
        $used = 10_000_000;
        $budget = new Budget(null, 100_000_000, 0.8, null, static function () use (&$used): int {
            return $used;
        });

        self::assertFalse($budget->exhausted());
        $used = 79_999_999;
        self::assertFalse($budget->exhausted());
        $used = 80_000_000;
        self::assertTrue($budget->exhausted(), 'A tick that dies at the limit loses its work: stop at 80 %.');
    }

    public function testUnlimitedInTheCommandLine(): void
    {
        self::assertSame('cli', PHP_SAPI);

        self::assertFalse(Budget::forThisProcess()->exhausted());
    }

    #[DataProvider('limits')]
    public function testParsesMemoryLimits(string $value, ?int $bytes): void
    {
        self::assertSame($bytes, Budget::parseBytes($value));
    }

    /** @return iterable<string, array{string, int|null}> */
    public static function limits(): iterable
    {
        yield 'unlimited' => ['-1', null];
        yield 'empty' => ['', null];
        yield 'plain bytes' => ['1073741824', 1073741824];
        yield 'kilobytes' => ['512K', 524288];
        yield 'megabytes' => ['128M', 134217728];
        yield 'gigabytes' => ['2G', 2147483648];
        yield 'lower case with space' => [' 64m ', 67108864];
        yield 'with B' => ['64MB', 67108864];
        yield 'nonsense' => ['lots', null];
    }
    /** @return iterable<string, array{string, array<string, mixed>, bool}> */
    public static function processKinds(): iterable
    {
        yield 'command line' => ['cli', [], true];
        yield 'debugger' => ['phpdbg', [], true];
        yield 'CGI binary run from a shell' => ['cgi-fcgi', [], true];
        yield 'plain CGI run from a shell' => ['cgi', ['argv' => ['x']], true];
        yield 'FastCGI request' => ['cgi-fcgi', ['REQUEST_METHOD' => 'GET'], false];
        yield 'PHP-FPM request' => ['fpm-fcgi', ['REQUEST_METHOD' => 'POST'], false];
        yield 'Apache module' => ['apache2handler', ['REQUEST_METHOD' => 'GET'], false];
    }

    /**
     * @param array<string, mixed> $server
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('processKinds')]
    public function testCommandLineMeansStartedFromAShell(string $sapi, array $server, bool $expected): void
    {
        self::assertSame($expected, Budget::isCommandLine($sapi, $server));
    }
}
