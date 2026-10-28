<?php

declare(strict_types=1);

namespace ThreadSafe\App;

use Closure;
use InvalidArgumentException;
use Throwable;
use ThreadSafe\Backup\BackupProgress;
use ThreadSafe\Backup\BackupRun;
use ThreadSafe\Backup\BackupState;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Crypto\Passphrases;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\EngineException;
use ThreadSafe\Engine\Executor;
use ThreadSafe\Engine\Lock;
use ThreadSafe\Engine\Run;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Storage\SpaceCheck;

/**
 * Running backups, for both front ends (docs/PHASE4.md 3.3, 3.4): the command `backup` and the pages of the web interface.
 *
 * The decisive question is which runs are **active** and which are **interrupted**. The engine's own test (`running` and no lock
 * held) is right for a command line run, whose process holds the lock for as long as it lives. A web run is one process per
 * tick: between two ticks nothing holds the lock although the person is watching. So a web run (see {@see BackupState::$web}) is
 * also active while its last tick is recent. At most one run is active at a time, in the whole instance.
 */
final class BackupService
{
    /** A web run whose last tick is older than this (seconds) is taken for interrupted, however long a tick may last. */
    public const int MINIMUM_GRACE = 60;

    /** How long a finished or failed run record is kept so that its page can still be shown. */
    private const int KEEP_FINISHED = 86400;

    private const int KEEP_FAILED = 604800;

    /** @var Closure(): int */
    private readonly Closure $clock;

    private readonly int $grace;

    /**
     * @param Closure(): Budget|null $budgets the budget of a tick; default: a share of the request time limit
     * @param Closure(): int|null    $clock   the time in seconds (for tests)
     * @param int|null               $grace   seconds a web run counts as active after its last tick; default: from the time limit
     */
    public function __construct(
        private readonly ProfileStore $profiles,
        private readonly PluginRegistry $plugins,
        private readonly RunStore $runs,
        private readonly ?string $dataDirectory = null,
        private readonly ?Closure $budgets = null,
        ?Closure $clock = null,
        ?int $grace = null,
        private readonly ?SpaceCheck $space = null,
    ) {
        $this->clock = $clock ?? time(...);
        $this->grace = $grace ?? max(self::MINIMUM_GRACE, (int) ceil(3 * 0.6 * (float) ini_get('max_execution_time')));
    }

    public function task(): BackupRun
    {
        return new BackupRun($this->profiles, $this->plugins, $this->dataDirectory, $this->clock, 500, $this->space);
    }

    public function executor(): Executor
    {
        return new Executor($this->runs, $this->task(), $this->budgets ?? Budget::forThisProcess(...));
    }

    /**
     * Every backup run on record, newest first.
     *
     * @return list<Run>
     */
    public function runs(): array
    {
        return $this->runs->all('backup');
    }

    /**
     * @throws EngineException there is no such run, or it is not a backup
     */
    public function find(string $runId): Run
    {
        $run = $this->runs->load($runId);
        if ($run->task !== 'backup') {
            throw new EngineException("Run \"$runId\" is not a backup.");
        }

        return $run;
    }

    /**
     * What a run is doing: `active` (being worked on, or waiting for its next tick), `interrupted` (it should be going and nobody is
     * working on it: it can be continued or given up), `finished` or `failed`.
     */
    public function state(Run $run): string
    {
        return match ($run->status) {
            RunStatus::Finished => 'finished',
            RunStatus::Failed => 'failed',
            RunStatus::Running => $this->isActive($run) ? 'active' : 'interrupted',
        };
    }

    private function isActive(Run $run): bool
    {
        if (Lock::isHeld($this->runs->lockPath($run->id))) {
            return true;
        }
        try {
            $web = BackupState::fromArray($run->state)->web;
        } catch (InvalidArgumentException) {
            $web = false;
        }

        return $web && ($this->clock)() - $run->updated < $this->grace;
    }

    /** The run that is active now, if any. */
    public function activeRun(?string $except = null): ?Run
    {
        foreach ($this->runs() as $run) {
            if ($run->id !== $except && $run->status === RunStatus::Running && $this->isActive($run)) {
                return $run;
            }
        }

        return null;
    }

    /**
     * @throws GuardFailed another backup is active
     */
    public function assertNoActiveRun(?string $except = null): void
    {
        $active = $this->activeRun($except);
        if ($active !== null) {
            $profile = is_string($active->state['profileId'] ?? null) ? $active->state['profileId'] : '?';

            throw new GuardFailed("Another backup is running (profile \"$profile\", run {$active->id}). One backup at a time.");
        }
    }

    /**
     * Starts a backup run for the web interface: the state is made, nothing is done yet; the ticks follow.
     *
     * A passphrase makes the backup one for that passphrase only (docs/PHASE5.md 3.5); it is kept in the run's state file (mode 0600)
     * until the run is done and is not shown anywhere.
     *
     * @throws GuardFailed another backup is active, there is no such profile, or the passphrase is too weak
     */
    public function startWeb(string $profileId, bool $force = false, string $passphrase = ''): Run
    {
        return $this->start($profileId, $force, snapshot: false, web: true, source: 'manual', passphrase: $passphrase);
    }

    /**
     * Starts a backup run for the trigger (docs/PHASE5.md 3.3): in the command line process of `threadsafe cron`, which holds the
     * lock of the run for as long as it lives and reads the database in one snapshot, or as a run of ticks, one per call of the address.
     *
     * @throws GuardFailed another backup is active, or there is no such profile
     */
    public function startScheduled(string $profileId, bool $snapshot): Run
    {
        return $this->start($profileId, false, $snapshot, web: !$snapshot, source: 'schedule');
    }

    /**
     * @throws GuardFailed another backup is active, or there is no such profile
     */
    private function start(string $profileId, bool $force, bool $snapshot, bool $web, string $source, string $passphrase = ''): Run
    {
        if ($passphrase !== '' && ($weak = Passphrases::weakness($passphrase)) !== null) {
            throw new GuardFailed($weak);
        }
        if (!ProfileStore::validId($profileId) || !$this->profiles->exists($profileId)) {
            throw new GuardFailed("There is no profile \"$profileId\".");
        }
        $this->assertNoActiveRun();
        $this->prune();

        return $this->executor()->start(['profile' => $profileId, 'force' => $force, 'snapshot' => $snapshot, 'web' => $web, 'source' => $source, 'passphrase' => $passphrase]);
    }

    /**
     * Does one tick. A tick that fails leaves nothing behind: the files of the run are removed, the record stays (as failed) for
     * the page that shows the reason.
     *
     * @throws EngineException the run is being worked on by another process right now ({@see EngineException::isBusy()}), or is not a run that can be ticked
     * @throws Throwable       what the tick threw
     */
    public function tick(string $runId): Run
    {
        $this->find($runId);

        try {
            return $this->executor()->execute($runId, 1);
        } catch (EngineException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->cleanUp($runId);

            throw $e;
        }
    }

    /**
     * Works on a run until it ends (the command line, the worker of the trigger: no time limit). A run that fails leaves nothing
     * behind but its record, as with {@see tick()}.
     *
     * @throws EngineException the run is being worked on by another process right now, or is not a run that can be worked on
     * @throws Throwable       what the work threw
     */
    public function complete(string $runId): Run
    {
        $this->find($runId);

        try {
            return $this->executor()->execute($runId);
        } catch (EngineException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->cleanUp($runId);

            throw $e;
        }
    }

    /**
     * Gives up a run: removes what it left in the storage and its record. This is possible whenever no process is working on the
     * run at this moment (the lock of the run is free) - also for a web run whose last tick was a second ago, which is how a
     * person cancels a backup that is waiting for its next tick.
     *
     * @throws GuardFailed a process is working on the run right now
     * @throws EngineException there is no such run
     */
    public function abandon(string $runId): void
    {
        $run = $this->find($runId);
        $lock = Lock::tryAcquire($this->runs->lockPath($runId), 'giving up run ' . $runId);
        if ($lock === null) {
            throw new GuardFailed('The backup is being worked on right now; try again in a moment.');
        }
        try {
            $this->task()->abandon($run->state);
            $this->runs->delete($runId);
        } finally {
            $lock->release();
        }
    }

    /**
     * Removes the record of a finished or failed run.
     *
     * @throws GuardFailed the run is still going
     * @throws EngineException there is no such run
     */
    public function dismiss(string $runId): void
    {
        $run = $this->find($runId);
        if ($run->status === RunStatus::Running) {
            throw new GuardFailed('The backup has not ended; give it up instead.');
        }
        $this->runs->delete($runId);
    }

    /**
     * What the step that is working right now does and how long the run has lasted, for the page that watches it.
     *
     * @return array{working: bool, note: string, seconds: int, elapsed: int}
     */
    public function live(Run $run): array
    {
        return \ThreadSafe\Web\RunView::live($this->runs, $run);
    }

    /**
     * How far a run is: the phase, a percentage (null where it cannot be told) and a sentence.
     *
     * @return array{phase: string, percent: int|null, label: string}
     */
    public function progress(Run $run): array
    {
        $measure = BackupProgress::of($run->state);

        return ['phase' => $measure['phase'], 'percent' => $run->status === RunStatus::Finished ? 100 : $measure['percent'], 'label' => $run->progress ?? 'starting'];
    }

    /**
     * What a finished run made.
     *
     * @return array<string, mixed>|null
     */
    public function result(Run $run): ?array
    {
        $result = $run->state['result'] ?? null;

        return is_array($result) ? $result : null;
    }

    /** Removes the records of old finished and failed runs. */
    public function prune(): void
    {
        $now = ($this->clock)();
        foreach ($this->runs() as $run) {
            $age = $now - $run->updated;
            if (($run->status === RunStatus::Finished && $age > self::KEEP_FINISHED) || ($run->status === RunStatus::Failed && $age > self::KEEP_FAILED)) {
                $this->runs->delete($run->id);
            }
        }
    }

    private function cleanUp(string $runId): void
    {
        try {
            $this->task()->abandon($this->runs->load($runId)->state);
        } catch (Throwable) {
            // the record is gone or unreadable: nothing to clean up by it
        }
    }
}
