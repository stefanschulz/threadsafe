<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\Throttle;

/**
 * The delay after attempts, shared by the key of the single-file tools and the login of the web interface.
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

    public function testThreeAttemptsAreFreeAndTheFourthHasToWait(): void
    {
        $throttle = $this->throttle();

        self::assertSame([0, 0, 0], [$throttle->begin(), $throttle->begin(), $throttle->begin()]);
        self::assertSame(5, $throttle->begin());
    }

    public function testTheWaitAfterTheFreeAttemptsDoublesUpToFiveMinutes(): void
    {
        $throttle = $this->throttle();
        $waits = [];
        for ($i = 0; $i < 9; ++$i) {
            $throttle->begin();
            $waits[] = $throttle->waitSeconds();
            // an attempt during the wait is turned away and not counted, so move on to the end of the wait
            $this->now += $throttle->waitSeconds();
        }

        self::assertSame([0, 0, 5, 10, 20, 40, 80, 160, 300], $waits);
    }

    public function testAnAttemptDuringTheWaitIsTurnedAwayAndNotCounted(): void
    {
        $throttle = $this->throttle();
        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(0, $throttle->begin());
        }

        self::assertSame(5, $throttle->begin());
        self::assertSame(5, $throttle->begin(), 'a refused attempt does not lengthen the wait');
        $this->now += 5;
        self::assertSame(0, $throttle->begin());
        self::assertSame(10, $throttle->waitSeconds(), 'the fourth counted attempt makes the wait 10 seconds');
    }

    public function testTheWaitRunsOutWithTheClockAndTheAttemptsExpireAfterAQuarterOfAnHour(): void
    {
        $throttle = $this->throttle();
        for ($i = 0; $i < 4; ++$i) {
            $this->now += $i === 3 ? 5 : 0;
            $throttle->begin();
        }
        self::assertSame(10, $throttle->waitSeconds());

        $this->now += 4;
        self::assertSame(6, $throttle->waitSeconds());
        $this->now += 6;
        self::assertSame(0, $throttle->waitSeconds());

        $this->now += 900;
        self::assertSame(0, $throttle->begin());
        self::assertSame(0, $throttle->waitSeconds(), 'the old attempts no longer count');
    }

    public function testLookingCountsNothing(): void
    {
        $throttle = $this->throttle();
        for ($i = 0; $i < 20; ++$i) {
            $throttle->waitSeconds();
        }

        self::assertSame(0, $throttle->begin());
        self::assertSame(0, $throttle->begin());
        self::assertSame(0, $throttle->begin());
        self::assertGreaterThan(0, $throttle->begin());
    }

    public function testASuccessWipesTheAttemptsAndTheStateFileIsPrivate(): void
    {
        $throttle = $this->throttle();
        for ($i = 0; $i < 4; ++$i) {
            $throttle->begin();
        }
        self::assertGreaterThan(0, $throttle->waitSeconds());
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0600, fileperms($this->directory . '/state/attempts.json') & 0777);
        }

        $throttle->reset();

        self::assertSame(0, $throttle->waitSeconds());
        self::assertFileDoesNotExist($this->directory . '/state/attempts.json');
    }

    public function testAResetWithoutAFileMakesNone(): void
    {
        $this->throttle()->reset();
        $this->throttle()->waitSeconds();

        self::assertFileDoesNotExist($this->directory . '/state/attempts.json');
    }

    public function testEveryFailureIsLoggedWithTheAddressButNothingElse(): void
    {
        $throttle = $this->throttle($this->directory . '/failures.log');

        $throttle->failed('203.0.113.9', 'wrong login');
        $throttle->failed("evil\nINJECTED", 'wrong login');

        $lines = file($this->directory . '/failures.log', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(2, $lines, 'a newline in the address cannot make a line of its own');
        self::assertStringEndsWith('wrong login from 203.0.113.9', $lines[0]);
        self::assertStringNotContainsString('INJECTED', $lines[1], 'only the characters of an address are kept');
    }

    /**
     * The hole that the review found: with a look and a note as two steps, 30 requests that arrive together were all compared (and one or two were
     * noted). Processes of their own wait for the same instant and then begin an attempt each; no more than the free ones may be let through.
     */
    public function testAttemptsThatArriveTogetherAreCountedOneByOne(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is needed to start the processes.');
        }
        $script = $this->directory . '/begin.php';
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__, 3) . '/src/Web/Throttle.php', true) . ';'
            . ' [, $file, $start] = $argv; while (microtime(true) < (float) $start) {}'
            . ' echo (new ThreadSafe\Web\Throttle($file))->begin() === 0 ? "1" : "0";');
        $file = $this->directory . '/parallel/attempts.json';
        $start = microtime(true) + 3.0;

        $processes = [];
        for ($i = 0; $i < 24; ++$i) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, '-n', $script, $file, (string) $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        $let = 0;
        foreach ($processes as [$process, $pipes]) {
            $let += (int) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        self::assertSame(3, $let, 'three attempts are free, whatever the number of requests that arrive together');
    }
}
