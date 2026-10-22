<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Cli;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Cli\Application;
use ThreadSafe\Cli\BufferedOutput;
use ThreadSafe\Cli\CronCommand;
use ThreadSafe\Schedule\Heartbeat;
use ThreadSafe\Schedule\Scheduler;
use ThreadSafe\Schedule\ScheduleStore;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * `threadsafe cron` as the application runs it: a profile that is due every hour, the real clock.
 */
final class CronCommandTest extends TestCase
{
    use TempDirectory;

    private BackupFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new BackupFixture($this->tempDirectory());
        $this->fixture->profiles->save($this->fixture->profiles->load('blog')->with(['schedule' => ['cron' => '0 * * * *']]));
        // the schedule has counted for two hours: this hour's time is due
        (new Scheduler(new ScheduleStore($this->fixture->data . '/schedule'), new DateTimeZone('UTC')))->reset('blog', new DateTimeImmutable('-2 hours'));
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function cli(array $arguments, bool $quiet = false): array
    {
        $output = new BufferedOutput(false, $quiet);
        $code = (new Application([new CronCommand($this->fixture->data)]))->run(['threadsafe', 'cron', ...$arguments], $output);

        return [$code, $output->stdout(), $output->stderr()];
    }

    public function testTheCommandBacksUpWhatIsDueAndThenHasNothingToDo(): void
    {
        [$code, $out, $err] = $this->cli([]);

        self::assertSame(0, $code, $err);
        self::assertStringContainsString('Backing up "blog"', $out);
        self::assertStringContainsString('Backup blog_', $out);
        self::assertCount(1, glob($this->fixture->storageDirectory . '/blog_*.json') ?: []);
        self::assertSame('cli', (new Heartbeat($this->fixture->data . '/heartbeat.json'))->read()['via'] ?? null);

        [$code, $out] = $this->cli([]);
        self::assertSame(0, $code);
        self::assertStringContainsString('Nothing to do.', $out);
        self::assertCount(1, glob($this->fixture->storageDirectory . '/blog_*.json') ?: []);
    }

    public function testQuietPrintsNothingWhenAllIsWell(): void
    {
        [$code, $out, $err] = $this->cli([], quiet: true);

        self::assertSame(0, $code);
        self::assertSame('', $out);
        self::assertSame('', $err);
        self::assertCount(1, glob($this->fixture->storageDirectory . '/blog_*.json') ?: []);
    }

    public function testAProblemIsPrintedEvenWhenQuietAndTheExitCodeSaysSo(): void
    {
        rename($this->fixture->site, $this->fixture->site . '-away');

        [$code, $out, $err] = $this->cli([], quiet: true);

        self::assertGreaterThanOrEqual(2, $code);
        self::assertSame('', $out);
        self::assertStringContainsString('The backup of "blog" failed', $err);
    }

    public function testAnArgumentIsAUsageError(): void
    {
        self::assertSame(1, $this->cli(['now'])[0]);
    }
}
