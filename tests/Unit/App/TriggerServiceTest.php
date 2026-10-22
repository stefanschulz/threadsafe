<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\App;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Admin\AuditLog;
use ThreadSafe\App\BackupService;
use ThreadSafe\App\TriggerService;
use ThreadSafe\App\WorkerLauncher;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\Executor;
use ThreadSafe\Engine\Lock;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Schedule\Heartbeat;
use ThreadSafe\Schedule\Scheduler;
use ThreadSafe\Schedule\ScheduleState;
use ThreadSafe\Schedule\ScheduleStore;
use ThreadSafe\Storage\BackupCatalog;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The trigger: the command (all the work in one process), the address in tick mode and in worker mode, and the scheduler's state after
 * success, failure, interruption and a missed time.
 */
final class TriggerServiceTest extends TestCase
{
    use TempDirectory;

    private BackupFixture $fixture;

    private ScheduleStore $states;

    /** the moment of the test, 2026-10-22 03:05 UTC; the schedule is "0 3 * * *" */
    private int $now;

    protected function setUp(): void
    {
        $this->fixture = new BackupFixture($this->tempDirectory());
        $this->states = new ScheduleStore($this->fixture->data . '/schedule');
        $this->now = (new DateTimeImmutable('2026-10-22 03:05:00', new DateTimeZone('UTC')))->getTimestamp();
        $this->fixture->profiles->save($this->fixture->profiles->load('blog')->with(['schedule' => ['cron' => '0 3 * * *']]));
        $this->scheduler()->reset('blog', new DateTimeImmutable('2026-10-21 12:00:00', new DateTimeZone('UTC')));
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function scheduler(): Scheduler
    {
        return new Scheduler($this->states, new DateTimeZone('UTC'), 360);
    }

    /**
     * @param (\Closure(): Budget)|null $budgets
     */
    private function trigger(string $mode = 'auto', ?WorkerLauncher $launcher = null, ?\Closure $budgets = null): TriggerService
    {
        $clock = fn (): int => $this->now;
        $backups = new BackupService($this->fixture->profiles, PluginRegistry::load(), $this->fixture->runs, $this->fixture->data, $budgets, $clock, 60);

        return new TriggerService($this->fixture->data, $this->fixture->profiles, $backups, $this->scheduler(), new Heartbeat($this->fixture->data . '/heartbeat.json'), new AuditLog($this->fixture->data . '/audit.log', 5242880, $clock), $mode, $launcher, $clock);
    }

    /** @return \Closure(): Budget a budget that ends every tick at once, so that a backup takes many ticks */
    private static function tiny(): \Closure
    {
        return static fn (): Budget => new Budget(0.0);
    }

    /** @return array{int, list<string>, list<string>} exit code, the lines, the problems */
    private function work(?TriggerService $trigger = null): array
    {
        $lines = [];
        $problems = [];
        $code = ($trigger ?? $this->trigger())->work(static function (string $line, bool $problem) use (&$lines, &$problems): void {
            if ($problem) {
                $problems[] = $line;
            } else {
                $lines[] = $line;
            }
        });

        return [$code, $lines, $problems];
    }

    private function backupCount(): int
    {
        return count(array_filter((new BackupCatalog($this->fixture->storage()))->entries('blog', false), static fn ($entry): bool => $entry->sidecar !== null));
    }

    private function audit(): string
    {
        return (string) @file_get_contents($this->fixture->data . '/audit.log');
    }

    /** @return array{time: int, via: string, note: string}|null */
    private function heartbeat(): ?array
    {
        return (new Heartbeat($this->fixture->data . '/heartbeat.json'))->read();
    }

    // ------------------------------------------------------------------------------------- the command

    public function testTheCommandRunsTheBackupThatIsDueOnceAndRemembersIt(): void
    {
        [$code, $lines, $problems] = $this->work();

        self::assertSame(0, $code, implode("\n", $problems));
        self::assertSame([], $problems);
        self::assertStringContainsString('Backing up "blog" (scheduled for 2026-10-22 03:00 UTC)', implode("\n", $lines));
        self::assertMatchesRegularExpression('/Backup blog_\S+: \d+ file\(s\), status /', implode("\n", $lines));
        self::assertSame(1, $this->backupCount());
        self::assertSame(ScheduleState::DONE, $this->states->load('blog')?->status);
        self::assertSame([], $this->fixture->runs->all('backup'), 'the record of a finished run is removed');
        $beat = $this->heartbeat();
        self::assertNotNull($beat);
        self::assertSame('cli', $beat['via']);
        self::assertStringContainsString('backed up "blog"', $beat['note']);
        self::assertStringContainsString('"event":"backup.started"', $this->audit());
        self::assertStringContainsString('"source":"schedule"', $this->audit());

        [$code, $lines] = $this->work();
        self::assertSame(0, $code);
        self::assertSame(['Nothing to do.'], $lines, 'a second call has nothing to do');
        self::assertSame(1, $this->backupCount());
    }

    public function testAnotherTriggerThatIsWorkingMakesTheCallEndQuietly(): void
    {
        $lock = Lock::tryAcquire($this->fixture->data . '/locks/cron.lock', 'a trigger');
        [$code, $lines] = $this->work();
        $lock?->release();

        self::assertSame(0, $code);
        self::assertStringContainsString('Another trigger is working', $lines[0]);
        self::assertSame(0, $this->backupCount());
        self::assertSame(ScheduleState::PENDING, $this->states->load('blog')?->status);
    }

    public function testABackupThatIsRunningElsewhereMakesTheDueOneWait(): void
    {
        $lock = Lock::tryAcquire($this->fixture->data . '/locks/backup.lock', 'backup of something');
        [$code, , $problems] = $this->work();
        $lock?->release();

        self::assertSame(2, $code, 'refused');
        self::assertStringContainsString('Another backup is running', $problems[0]);
        self::assertSame(0, $this->backupCount());

        [$code] = $this->work();
        self::assertSame(0, $code);
        self::assertSame(1, $this->backupCount(), 'the next call makes it up');
    }

    public function testAMissedTimeIsGivenUpAndSaidSo(): void
    {
        $this->now += 10 * 3600;   // 13:05, ten hours late

        [$code, , $problems] = $this->work();

        self::assertSame(0, $code);
        self::assertStringContainsString('was given up', $problems[0]);
        self::assertSame(0, $this->backupCount());
        self::assertStringContainsString('"event":"schedule.skipped"', $this->audit());
        self::assertSame(ScheduleState::SKIPPED, $this->states->load('blog')?->status);
    }

    public function testAFailedBackupIsTriedAgainAndGivenUpAfterThreeAttempts(): void
    {
        rename($this->fixture->site, $this->fixture->site . '-away');   // the site is gone: the backup fails

        [$code, , $problems] = $this->work();
        self::assertGreaterThanOrEqual(2, $code);
        self::assertStringContainsString('The backup of "blog" failed', $problems[0]);
        self::assertStringContainsString('tried again', $problems[0]);
        self::assertSame(ScheduleState::PENDING, $this->states->load('blog')?->status);
        self::assertStringContainsString('"event":"backup.failed"', $this->audit());

        [, $lines] = $this->work();
        self::assertSame(['Nothing to do.'], $lines, 'a quarter of an hour has not passed');

        $this->now += 16 * 60;
        [$code] = $this->work();
        self::assertGreaterThanOrEqual(2, $code, 'the second attempt fails as well');
        $this->now += 16 * 60;
        [$code, , $problems] = $this->work();
        self::assertGreaterThanOrEqual(2, $code);
        self::assertStringContainsString('No more attempts', $problems[0]);
        self::assertSame(ScheduleState::GAVE_UP, $this->states->load('blog')?->status);

        $this->now += 16 * 60;
        [, $lines] = $this->work();
        self::assertSame(['Nothing to do.'], $lines, 'given up for this scheduled time');
    }

    public function testTheSecondAttemptSucceedsOnceTheSiteIsBack(): void
    {
        rename($this->fixture->site, $this->fixture->site . '-away');
        $this->work();
        rename($this->fixture->site . '-away', $this->fixture->site);

        $this->now += 16 * 60;
        [$code, $lines] = $this->work();

        self::assertSame(0, $code);
        self::assertStringContainsString('attempt 2', implode("\n", $lines));
        self::assertSame(1, $this->backupCount());
        self::assertSame(ScheduleState::DONE, $this->states->load('blog')?->status);
        self::assertSame([], $this->fixture->runs->all('backup'), 'the records of the failed attempts are gone once a backup has succeeded');
    }

    public function testAnInterruptedRunIsContinuedAndReportsToItsScheduledTime(): void
    {
        $service = new BackupService($this->fixture->profiles, PluginRegistry::load(), $this->fixture->runs, $this->fixture->data, self::tiny(), fn (): int => $this->now, 60);
        $run = $service->startScheduled('blog', snapshot: true);
        $this->scheduler()->started('blog', new DateTimeImmutable('2026-10-22 03:00:00', new DateTimeZone('UTC')), new DateTimeImmutable('@' . $this->now), $run->id);
        (new Executor($this->fixture->runs, $service->task(), self::tiny()))->execute($run->id, 1);   // the process "died" here

        [$code, $lines] = $this->work();

        self::assertSame(0, $code);
        self::assertStringContainsString('Continuing the interrupted backup of "blog"', $lines[0]);
        self::assertSame(1, $this->backupCount());
        self::assertSame(ScheduleState::DONE, $this->states->load('blog')?->status);
        [, $again] = $this->work();
        self::assertSame(['Nothing to do.'], $again);
        self::assertSame(1, $this->backupCount(), 'and the scheduled time is not run a second time');
    }

    public function testAProfileWithoutAScheduleIsNeverStarted(): void
    {
        $this->fixture->profiles->save($this->fixture->profiles->load('blog')->with(['schedule' => null]));

        [, $lines] = $this->work();

        self::assertSame(['Nothing to do.'], $lines);
        self::assertSame(0, $this->backupCount());
    }

    // --------------------------------------------------------------------------- the address, in ticks

    public function testTheAddressInTickModeBacksUpOneTickPerCall(): void
    {
        $trigger = fn (): TriggerService => $this->trigger('ticks', null, self::tiny());

        $first = $trigger()->call();
        self::assertSame('working', $first['status']);
        self::assertStringContainsString('backing up "blog"', $first['lines'][0]);
        $run = $this->fixture->runs->all('backup')[0];
        self::assertSame($run->id, $this->states->load('blog')?->runId, 'the schedule knows its run');
        self::assertSame(ScheduleState::RUNNING, $this->states->load('blog')?->status);
        self::assertTrue($run->state['web'], 'a run of ticks is a web run: active while its last tick is recent');
        self::assertFalse($run->state['snapshot'], 'the database is read table by table');

        $calls = 1;
        $status = 'working';
        while ($status === 'working' && $calls < 300) {
            $this->now += 61;   // longer than a web run counts as active: each call continues it
            $status = $trigger()->call()['status'];
            ++$calls;
        }

        self::assertSame('idle', $status);
        self::assertGreaterThan(3, $calls, 'it took several calls');
        self::assertSame(1, $this->backupCount());
        self::assertSame(ScheduleState::DONE, $this->states->load('blog')?->status);
        self::assertSame('web', $this->heartbeat()['via'] ?? null);
        self::assertSame('idle', $trigger()->call()['status'], 'nothing more to do');
        self::assertSame(1, $this->backupCount());
    }

    public function testACallWhileAnotherIsWorkingStartsNothing(): void
    {
        $lock = Lock::tryAcquire($this->fixture->data . '/locks/cron.lock', 'another call');

        $answer = $this->trigger('ticks', null, self::tiny())->call();
        $lock?->release();

        self::assertSame('working', $answer['status']);
        self::assertSame([], $this->fixture->runs->all('backup'), 'nothing was started');
    }

    public function testAFailureInTickModeIsReportedAndRecorded(): void
    {
        rename($this->fixture->site, $this->fixture->site . '-away');

        $answer = $this->trigger('ticks', null, self::tiny())->call();

        self::assertStringContainsString('The backup of "blog" failed', implode("\n", $answer['lines']));
        self::assertSame(ScheduleState::PENDING, $this->states->load('blog')?->status);
        self::assertStringContainsString('"event":"backup.failed"', $this->audit());
        self::assertSame(RunStatus::Failed, $this->fixture->runs->all('backup')[0]->status);
    }

    // --------------------------------------------------------------------------- the address, as a worker

    /**
     * @param list<string> $commands where the commands are collected that the fake shell is given
     */
    private function launcher(array &$commands, bool $works = true): WorkerLauncher
    {
        return new WorkerLauncher($this->fixture->data, '/usr/bin/php8.4', static function (string $command) use (&$commands, $works): bool {
            $commands[] = $command;

            return $works;
        }, fn (): int => $this->now);
    }

    public function testTheAddressStartsOneWorkerWhenSomethingIsDue(): void
    {
        $commands = [];
        $trigger = function () use (&$commands): TriggerService {
            return $this->trigger('auto', $this->launcher($commands));
        };

        $first = $trigger()->call();
        self::assertSame('started', $first['status']);
        self::assertCount(1, $commands);
        self::assertSame(0, $this->backupCount(), 'the worker is a process of its own; the request does no backup');

        $again = $trigger()->call();
        self::assertSame('working', $again['status'], 'a second call a moment later starts nothing');
        self::assertCount(1, $commands);

        $this->now += 31;
        self::assertSame('started', $trigger()->call()['status'], 'half a minute later, with the work still waiting, one more may be started: the worker takes the lock, so two never work');
        self::assertCount(2, $commands);
    }

    public function testTheWorkerIsStartedWithACleanEnvironmentAndNoStandardInput(): void
    {
        $commands = [];
        $this->trigger('auto', $this->launcher($commands))->call();

        $command = $commands[0];
        self::assertStringStartsWith('env -i PATH=/usr/local/bin:/usr/bin:/bin THREADSAFE_DATA=', $command);
        self::assertStringContainsString("'/usr/bin/php8.4' -q ", $command);
        self::assertStringContainsString("bin/threadsafe' cron --quiet < /dev/null > ", $command);
        self::assertStringEndsWith(' 2>&1 &', $command);
        self::assertStringContainsString('nohup', $command);
    }

    public function testNothingDueMeansNoWorker(): void
    {
        $this->now = (new DateTimeImmutable('2026-10-22 02:00:00', new DateTimeZone('UTC')))->getTimestamp();
        $commands = [];

        $answer = $this->trigger('auto', $this->launcher($commands))->call();

        self::assertSame('idle', $answer['status']);
        self::assertSame([], $commands);
    }

    public function testAWorkerThatIsAliveAnswersWorking(): void
    {
        $commands = [];
        $lock = Lock::tryAcquire($this->fixture->data . '/locks/cron.lock', 'the worker');

        $answer = $this->trigger('auto', $this->launcher($commands))->call();
        $lock?->release();

        self::assertSame('working', $answer['status']);
        self::assertSame([], $commands);
    }

    public function testWhenNoProcessCanBeStartedAutoFallsBackToTicksAndWorkerModeSaysSo(): void
    {
        $commands = [];
        $auto = $this->trigger('auto', $this->launcher($commands, works: false), self::tiny())->call();
        self::assertSame('working', $auto['status']);
        self::assertStringContainsString('working in ticks', implode("\n", $auto['lines']));
        self::assertNotSame([], $this->fixture->runs->all('backup'), 'a tick was done');

        $this->fixture->runs->delete($this->fixture->runs->all('backup')[0]->id);
        $this->now += 40;
        $this->scheduler()->reset('blog', new DateTimeImmutable('2026-10-21 12:00:00', new DateTimeZone('UTC')));
        $worker = $this->trigger('worker', $this->launcher($commands, works: false))->call();
        self::assertSame('idle', $worker['status']);
        self::assertStringContainsString('no worker could be started', $worker['lines'][0]);

        $noLauncher = $this->trigger('worker', null)->call();
        self::assertSame('idle', $noLauncher['status']);
        self::assertStringContainsString('can be started on this host', implode("\n", $noLauncher['lines']));
    }
}
