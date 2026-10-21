<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\Throttle;

/**
 * The delay after wrong attempts, shared by the key of the restore wizard and the login of the web interface.
 */
final class ThrottleTest extends TestCase
{
    use TempDirectory;

    private string $directory;

    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->directory = $this->tempDirectory();
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function throttle(?string $log = null): Throttle
    {
        return new Throttle($this->directory . '/state/attempts.json', $log, fn (): int => $this->now);
    }

    public function testThreeFailuresAreFreeThenTheWaitDoublesUpToFiveMinutes(): void
    {
        $throttle = $this->throttle();
        $waits = [];
        for ($i = 0; $i < 9; ++$i) {
            $throttle->fail('127.0.0.1', 'wrong login');
            $waits[] = $throttle->waitSeconds();
        }

        self::assertSame([0, 0, 5, 10, 20, 40, 80, 160, 300], $waits);
    }

    public function testTheWaitRunsOutWithTheClockAndTheFailuresExpireAfterAQuarterOfAnHour(): void
    {
        $throttle = $this->throttle();
        for ($i = 0; $i < 4; ++$i) {
            $throttle->fail('127.0.0.1', 'wrong login');
        }
        self::assertSame(10, $throttle->waitSeconds());

        $this->now += 4;
        self::assertSame(6, $throttle->waitSeconds());
        $this->now += 6;
        self::assertSame(0, $throttle->waitSeconds());

        $this->now += 900;
        $throttle->fail('127.0.0.1', 'wrong login');
        self::assertSame(0, $throttle->waitSeconds(), 'the old failures no longer count');
    }

    public function testASuccessWipesTheFailuresAndTheStateFileIsPrivate(): void
    {
        $throttle = $this->throttle();
        for ($i = 0; $i < 5; ++$i) {
            $throttle->fail('127.0.0.1', 'wrong login');
        }
        self::assertGreaterThan(0, $throttle->waitSeconds());
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0600, fileperms($this->directory . '/state/attempts.json') & 0777);
        }

        $throttle->reset();

        self::assertSame(0, $throttle->waitSeconds());
        self::assertFileDoesNotExist($this->directory . '/state/attempts.json');
    }

    public function testEveryFailureIsLoggedWithTheAddressButNothingElse(): void
    {
        $throttle = $this->throttle($this->directory . '/failures.log');

        $throttle->fail('203.0.113.9', 'wrong login');
        $throttle->fail("evil\nINJECTED", 'wrong login');

        $lines = file($this->directory . '/failures.log', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(2, $lines, 'a newline in the address cannot make a line of its own');
        self::assertStringEndsWith('wrong login from 203.0.113.9', $lines[0]);
        self::assertStringNotContainsString('INJECTED', $lines[1], 'only the characters of an address are kept');
    }
}
