<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\App;

use PHPUnit\Framework\TestCase;
use ThreadSafe\App\BackupService;
use ThreadSafe\Backup\BackupProgress;
use ThreadSafe\Backup\BackupState;
use ThreadSafe\Cli\Application;
use ThreadSafe\Cli\BackupCommand;
use ThreadSafe\Cli\BufferedOutput;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\EngineException;
use ThreadSafe\Engine\Lock;
use ThreadSafe\Engine\Run;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * Running backups for the web interface and the command line: which runs are active, the ticks, giving up, the progress.
 */
final class BackupServiceTest extends TestCase
{
    use TempDirectory;

    private BackupFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new BackupFixture($this->tempDirectory());
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @param (\Closure(): Budget)|null $budgets
     */
    private function service(?\Closure $budgets = null, ?int $now = null): BackupService
    {
        return new BackupService($this->fixture->profiles, PluginRegistry::load(), $this->fixture->runs, $this->fixture->data, $budgets ?? static fn (): Budget => new Budget(0.0), $now === null ? null : static fn (): int => $now, 60);
    }

    /** A run as the engine stores it, with the time of its last tick. */
    private function record(string $id, RunStatus $status, bool $web, int $updated, string $profile = 'blog'): Run
    {
        $state = new BackupState($profile, false, false, null, null);
        $state->web = $web;
        $run = new Run($id, 'backup', $status, 1, $updated, $updated, null, null, $state->toArray());
        $this->fixture->runs->save($run);

        return $run;
    }

    public function testAWebRunIsActiveWhileItsLastTickIsRecentAndInterruptedAfterwards(): void
    {
        $run = $this->record('backup-20261021-120000-aaaaaaaa', RunStatus::Running, web: true, updated: 1000);

        self::assertSame('active', $this->service(now: 1030)->state($run), '30 s after the last tick: the person is still looking');
        self::assertSame('interrupted', $this->service(now: 1061)->state($run), 'a minute without a tick');
    }

    public function testACommandLineRunIsActiveOnlyWhileItsProcessHoldsTheLock(): void
    {
        $run = $this->record('backup-20261021-120001-bbbbbbbb', RunStatus::Running, web: false, updated: 1000);
        $service = $this->service(now: 1001);

        self::assertSame('interrupted', $service->state($run), 'no lock: the process is gone, however recent its last tick');

        $lock = Lock::tryAcquire($this->fixture->runs->lockPath($run->id));
        self::assertSame('active', $service->state($run));
        $lock?->release();
    }

    public function testFinishedAndFailedRunsAreNeitherActiveNorInterrupted(): void
    {
        $service = $this->service(now: 1001);

        self::assertSame('finished', $service->state($this->record('backup-20261021-120002-cccccccc', RunStatus::Finished, web: true, updated: 1000)));
        self::assertSame('failed', $service->state($this->record('backup-20261021-120003-dddddddd', RunStatus::Failed, web: true, updated: 1000)));
        self::assertNull($service->activeRun());
    }

    public function testOnlyOneBackupMayBeActive(): void
    {
        $active = $this->record('backup-20261021-120004-eeeeeeee', RunStatus::Running, web: true, updated: 1000);
        $service = $this->service(now: 1010);

        self::assertSame($active->id, $service->activeRun()?->id);
        self::assertNull($service->activeRun($active->id), 'a run is not in its own way');
        try {
            $service->assertNoActiveRun();
            self::fail('a second backup must be refused');
        } catch (GuardFailed $e) {
            self::assertStringContainsString('One backup at a time', $e->getMessage());
            self::assertStringContainsString($active->id, $e->getMessage());
        }
        try {
            $service->startWeb('blog');
            self::fail('a second backup must be refused');
        } catch (GuardFailed) {
            self::assertCount(1, $service->runs(), 'nothing was started');
        }
        $service->assertNoActiveRun($active->id);
        $this->addToAssertionCount(1);
    }

    public function testAWebBackupIsDoneInTicksAndLeavesAStandardArchive(): void
    {
        $service = $this->service();
        $run = $service->startWeb('blog');
        self::assertTrue(BackupState::fromArray($run->state)->web);
        self::assertSame('active', $service->state($run));

        $percentages = [];
        $ticks = 0;
        while ($run->status === RunStatus::Running) {
            $run = $service->tick($run->id);
            $percentages[] = $service->progress($run)['percent'];
            self::assertLessThan(200, ++$ticks, 'the backup should end');
        }

        self::assertGreaterThan(3, $ticks, 'a budget of nothing cuts the work into many ticks');
        self::assertSame(100, end($percentages));
        $known = array_values(array_filter($percentages, 'is_int'));
        $sorted = $known;
        sort($sorted);
        self::assertSame($sorted, $known, 'the bar never goes back');
        $result = $service->result($run);
        self::assertIsArray($result);
        self::assertSame('ok', $result['status'] ?? null);
        self::assertNotSame('snapshot', $result['consistency'] ?? null, 'a web run never reads in one snapshot (this site has no database at all)');
        $parts = $result['parts'];
        self::assertIsArray($parts);
        self::assertFileExists($this->fixture->storageDirectory . '/' . $parts[0]);
        self::assertSame('finished', $service->state($run));
    }

    public function testATickThatFailsRemovesWhatTheRunWroteAndKeepsTheReason(): void
    {
        $service = $this->service();
        $run = $service->startWeb('blog');
        $service->tick($run->id);
        self::assertNotSame([], glob($this->fixture->storageDirectory . '/.work-*') ?: [], 'the first tick made a work directory');

        // the site disappears under the run
        $this->removeTree($this->fixture->site);
        $failure = null;
        for ($i = 0; $i < 100 && $failure === null; ++$i) {
            try {
                $service->tick($run->id);
            } catch (\Throwable $e) {
                $failure = $e;
            }
        }
        self::assertNotNull($failure, 'the backup of a vanished site must fail');
        self::assertNotSame('', $failure->getMessage());

        $failed = $service->find($run->id);
        self::assertSame(RunStatus::Failed, $failed->status);
        self::assertNotNull($failed->error);
        self::assertSame([], glob($this->fixture->storageDirectory . '/.work-*') ?: [], 'no work directory is left');
        self::assertSame('failed', $service->state($failed));
        self::assertNull($service->activeRun(), 'a failed run does not block the next one');
    }

    public function testATickOfARunThatAnotherProcessIsTickingIsBusyNotAFailure(): void
    {
        $service = $this->service();
        $run = $service->startWeb('blog');
        $lock = Lock::tryAcquire($this->fixture->runs->lockPath($run->id));

        try {
            $service->tick($run->id);
            self::fail('the run is busy');
        } catch (EngineException $e) {
            self::assertTrue($e->isBusy());
        }
        $lock?->release();
        self::assertSame(RunStatus::Running, $service->find($run->id)->status, 'busy does not fail the run');
        self::assertSame(1, $service->tick($run->id)->ticks);
    }

    public function testAnUnknownRunOrOneOfAnotherTaskIsRefused(): void
    {
        $service = $this->service();
        foreach (['backup-20261021-120009-ffffffff', 'nonsense'] as $id) {
            try {
                $service->tick($id);
                self::fail("$id should be refused");
            } catch (EngineException $e) {
                self::assertFalse($e->isBusy());
            }
        }
    }

    public function testAnInterruptedRunCanBeGivenUpAndItsFilesGoWithIt(): void
    {
        $service = $this->service();
        $run = $service->startWeb('blog');
        for ($i = 0; $i < 4; ++$i) {
            $service->tick($run->id);
        }
        self::assertNotSame([], glob($this->fixture->storageDirectory . '/.work-*') ?: []);

        $service->abandon($run->id);

        self::assertSame([], $service->runs());
        self::assertSame([], glob($this->fixture->storageDirectory . '/.work-*') ?: []);
        self::assertSame([], glob($this->fixture->storageDirectory . '/*.zip') ?: []);
    }

    public function testAFinishedRunCannotBeGivenUpBecauseThatWouldRemoveTheBackup(): void
    {
        $service = $this->service();
        $run = $service->startWeb('blog');
        for ($i = 0; $i < 200 && $run->status !== RunStatus::Finished; ++$i) {
            $run = $service->tick($run->id);
        }
        self::assertSame(RunStatus::Finished, $run->status);

        try {
            $service->abandon($run->id);
            self::fail('a finished backup must not be removed by giving up the run');
        } catch (GuardFailed $e) {
            self::assertStringContainsString('finished', $e->getMessage());
        }
        self::assertNotSame([], glob($this->fixture->storageDirectory . '/*.zip') ?: []);
    }

    public function testARunThatAProcessIsWorkingOnCannotBeGivenUp(): void
    {
        $service = $this->service();
        $run = $service->startWeb('blog');
        $lock = Lock::tryAcquire($this->fixture->runs->lockPath($run->id));

        try {
            $service->abandon($run->id);
            self::fail('a run in the middle of a tick must not be given up');
        } catch (GuardFailed $e) {
            self::assertStringContainsString('right now', $e->getMessage());
        }
        $lock?->release();
        self::assertCount(1, $service->runs());
    }

    public function testRecordsOfEndedRunsCanBeDismissedButNotRunningOnes(): void
    {
        $service = $this->service();
        $this->record('backup-20261021-120010-11111111', RunStatus::Failed, web: true, updated: 1000);
        $running = $this->record('backup-20261021-120011-22222222', RunStatus::Running, web: true, updated: 1000);

        $service->dismiss('backup-20261021-120010-11111111');
        self::assertCount(1, $service->runs());
        $this->expectException(GuardFailed::class);
        $service->dismiss($running->id);
    }

    public function testOldRecordsOfEndedRunsAreRemovedWhenANewOneStarts(): void
    {
        $now = 2_000_000_000;
        $this->record('backup-20261021-120012-33333333', RunStatus::Finished, web: true, updated: $now - 90_000);
        $this->record('backup-20261021-120013-44444444', RunStatus::Finished, web: true, updated: $now - 600);
        $this->record('backup-20261021-120014-55555555', RunStatus::Failed, web: true, updated: $now - 700_000);
        $this->record('backup-20261021-120015-66666666', RunStatus::Failed, web: true, updated: $now - 3_600);

        $this->service(now: $now)->prune();

        self::assertEqualsCanonicalizing(['backup-20261021-120013-44444444', 'backup-20261021-120015-66666666'], array_map(static fn (Run $r): string => $r->id, $this->service()->runs()));
    }

    public function testTheCommandLineRefusesToStartNextToAnActiveWebRun(): void
    {
        $this->record('backup-20261021-120016-77777777', RunStatus::Running, web: true, updated: time());
        $application = new Application([new BackupCommand($this->fixture->data, PluginRegistry::load())]);
        $output = new BufferedOutput();

        $code = $application->run(['threadsafe', 'backup', 'blog'], $output);

        self::assertSame(2, $code);
        self::assertStringContainsString('One backup at a time', $output->stderr());
        self::assertSame([], glob($this->fixture->storageDirectory . '/*.zip') ?: []);
    }

    public function testTheCommandLineMayStartAgainRightAfterItsOwnProcessWasKilled(): void
    {
        // a run of the command line whose process died a second ago: the lock is gone, and that is all the command line needs to know
        $this->record('backup-20261021-120017-88888888', RunStatus::Running, web: false, updated: time());
        $application = new Application([new BackupCommand($this->fixture->data, PluginRegistry::load())]);
        $output = new BufferedOutput();

        $code = $application->run(['threadsafe', 'backup', 'blog'], $output);

        self::assertSame(0, $code, $output->stderr());
    }

    public function testTheProgressBarIsReadFromTheSavedState(): void
    {
        $state = new BackupState('blog', false, false, null, null);
        self::assertSame(['phase' => 'init', 'percent' => null], BackupProgress::of($state->toArray()));
        $state->phase = BackupState::SCAN;
        self::assertSame(['phase' => 'scan', 'percent' => null], BackupProgress::of($state->toArray()), 'while the site is listed nothing can be measured');
        $state->phase = BackupState::FINALE;
        self::assertSame(98, BackupProgress::of($state->toArray())['percent']);
        $state->phase = BackupState::DONE;
        self::assertSame(100, BackupProgress::of($state->toArray())['percent']);
        self::assertSame(['phase' => 'init', 'percent' => null], BackupProgress::of(['nonsense' => true]));
    }

    private function removeTree(string $path): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
