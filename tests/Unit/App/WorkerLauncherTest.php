<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\App;

use PHPUnit\Framework\TestCase;
use ThreadSafe\App\WorkerLauncher;
use ThreadSafe\Tests\Support\TempDirectory;

final class WorkerLauncherTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    public function testTheCommandHasAnEmptyEnvironmentTheDataDirectoryAndNoInput(): void
    {
        $data = $this->tempDirectory() . '/data with space';
        $launcher = new WorkerLauncher($data, '/usr/bin/php8.4');

        $command = $launcher->command('/usr/bin/php8.4');

        self::assertStringStartsWith('env -i PATH=/usr/local/bin:/usr/bin:/bin THREADSAFE_DATA=\'' . $data . '\' ', $command);
        self::assertStringContainsString(' cron --quiet < /dev/null > \'' . $data . '/cron-worker.log\' 2>&1 &', $command);
        self::assertStringNotContainsString('REQUEST_METHOD', $command);
        self::assertStringNotContainsString('QUERY_STRING', $command);
    }

    public function testNoSecondWorkerIsStartedWithinHalfAMinute(): void
    {
        $now = 5000;
        $started = 0;
        $launcher = new WorkerLauncher($this->tempDirectory(), '/usr/bin/php8.4', static function () use (&$started): bool {
            ++$started;

            return true;
        }, static function () use (&$now): int {
            return $now;
        });

        self::assertNull($launcher->start());
        $now += 29;
        self::assertSame('a worker was started a moment ago', $launcher->start());
        $now += 2;
        self::assertNull($launcher->start());
        self::assertSame(2, $started);
    }

    public function testAProcessThatCannotBeStartedIsReported(): void
    {
        $launcher = new WorkerLauncher($this->tempDirectory(), '/usr/bin/php8.4', static fn (): bool => false);

        self::assertSame('the process could not be started', $launcher->start());
    }
}
