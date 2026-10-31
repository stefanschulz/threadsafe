<?php

declare(strict_types=1);

namespace ThreadSafe\App;

use Closure;
use DateTimeImmutable;
use Throwable;
use ThreadSafe\Admin\AuditLog;
use ThreadSafe\Admin\Config;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Engine\EngineException;
use ThreadSafe\Engine\Lock;
use ThreadSafe\Engine\Run;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Schedule\DueRun;
use ThreadSafe\Schedule\Heartbeat;
use ThreadSafe\Schedule\SchedulePlan;
use ThreadSafe\Schedule\Scheduler;
use ThreadSafe\Schedule\ScheduleStore;
use ThreadSafe\Storage\SpaceCheck;

/**
 * What the trigger does when it is called (docs/PHASE5.md 3.3), for both ways of calling it:
 *
 * - {@see work()}, the command `threadsafe cron`: a command line process with no time limit; it continues interrupted runs, then starts
 *   every scheduled backup that is due, one after the other, each to its end, the database read in one snapshot;
 * - {@see call()}, the address `/cron/<secret>`: starts a detached worker that does the same (`worker` mode), or does one tick of work in
 *   the request (`ticks` mode: the database is then read table by table).
 *
 * Only one trigger works at a time (`locks/cron.lock`); a second call finds that out and ends. Scheduled backups wait for a manual one and
 * the other way round. The scheduler's state follows every run (started, succeeded, failed: tried again or given up), so a run that was
 * interrupted and is continued later reports to the right scheduled instant.
 */
final class TriggerService
{
    public const string IDLE = 'idle';

    public const string STARTED = 'started';

    public const string WORKING = 'working';

    /** The audit "user" of everything the trigger does. */
    private const string USER = 'trigger';

    /** Returned inside this class: a backup is running elsewhere, so nothing more is started now. */
    private const int STOP = -1;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param string                $mode     `auto`, `worker` or `ticks` (the setting `cron.mode`)
     * @param WorkerLauncher|null   $launcher null = this host cannot start a process
     * @param Closure(): int|null   $clock    the time in seconds (for tests)
     */
    public function __construct(
        private readonly string $data,
        private readonly ProfileStore $profiles,
        private readonly BackupService $backups,
        private readonly Scheduler $scheduler,
        private readonly Heartbeat $heartbeat,
        private readonly AuditLog $audit,
        private readonly string $mode = 'auto',
        private readonly ?WorkerLauncher $launcher = null,
        ?Closure $clock = null,
        private readonly ?RetentionService $retention = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /**
     * The trigger of a data directory, with the settings of its configuration.
     *
     * @param Closure(): int|null                         $clock   for tests: the time in seconds
     * @param (Closure(): \ThreadSafe\Engine\Budget)|null $budgets for tests: the budget of a tick
     * @param WorkerLauncher|null                         $launcher for tests; default: the real one where the host can start a process
     *
     * @throws \ThreadSafe\Admin\ConfigException the configuration file is damaged
     */
    public static function create(string $data, ?Closure $clock = null, ?Closure $budgets = null, ?WorkerLauncher $launcher = null): self
    {
        $config = Config::load($data . '/config.json');
        $profiles = new ProfileStore($data . '/profiles');
        $backups = new BackupService($profiles, PluginRegistry::load(), new RunStore($data . '/runs'), $data, $budgets, $clock);
        $scheduler = new Scheduler(new ScheduleStore($data . '/schedule'), $config->scheduleZone(), $config->scheduleGraceMinutes());

        return new self(
            $data,
            $profiles,
            $backups,
            $scheduler,
            new Heartbeat($data . '/heartbeat.json'),
            new AuditLog($data . '/audit.log', $config->auditMaxBytes(), $clock),
            $config->cronMode(),
            $launcher ?? (WorkerLauncher::available() ? new WorkerLauncher($data, clock: $clock) : null),
            $clock,
            RetentionService::create($data, $clock),
        );
    }

    // ----------------------------------------------------------------------------------- the command line

    /**
     * Does all the work that is due, in this process.
     *
     * @param Closure(string, bool): void $say what happens, one line; the flag says that it is a problem
     *
     * @return int 0 nothing went wrong, 2 something was refused, 3 a backup failed
     */
    public function work(Closure $say): int
    {
        $this->heartbeat->record(Heartbeat::CLI, 'called', ($this->clock)());
        $lock = Lock::tryAcquire($this->lockPath(), 'trigger, pid ' . getmypid());
        if ($lock === null) {
            $say('Another trigger is working (' . (Lock::holder($this->lockPath()) ?? 'holder unknown') . '); nothing to do.', false);
            $this->heartbeat->record(Heartbeat::CLI, 'another trigger was working', ($this->clock)());

            return 0;
        }

        $done = [];
        $exit = 0;
        try {
            foreach ($this->interrupted() as $run) {
                $exit = max($exit, $this->continueRun($run, $say, $done));
            }
            $plan = $this->scheduler->plan($this->profiles->all(), $this->now());
            $this->acknowledgeSkipped($plan, $say);
            foreach ($plan->due as $due) {
                $code = $this->runDue($due, $say, $done);
                if ($code === self::STOP) {
                    $exit = max($exit, 2);   // refused: a backup is running elsewhere, the rest waits for the next call

                    break;
                }
                $exit = max($exit, $code);
            }
            $this->hourlyRetention($say, $done);
            if ($done === []) {
                $say('Nothing to do.', false);
            }
        } finally {
            $this->heartbeat->record(Heartbeat::CLI, $done === [] ? 'nothing to do' : implode('; ', $done), ($this->clock)());
            $lock->release();
        }

        return $exit;
    }

    // ---------------------------------------------------------------------------------------- the address

    /**
     * The work of one call of the address.
     *
     * @return array{status: string, lines: list<string>} the status is {@see IDLE}, {@see STARTED} or {@see WORKING}
     */
    public function call(): array
    {
        $this->heartbeat->record(Heartbeat::WEB, 'called', ($this->clock)());
        $answer = $this->answer();
        if ($answer['status'] === self::IDLE) {
            // nothing to back up: the moment for the hourly retention (a site that makes no backups any more still ages)
            $lines = $answer['lines'];
            $done = [];
            $this->hourlyRetention(static function (string $line, bool $problem = false) use (&$lines): void {
                $lines[] = $line;
            }, $done);
            $answer = ['status' => self::IDLE, 'lines' => $lines];
        }
        $this->heartbeat->record(Heartbeat::WEB, $answer['status'] . ($answer['lines'] === [] ? '' : ': ' . $answer['lines'][0]), ($this->clock)());

        return $answer;
    }

    /**
     * @return array{status: string, lines: list<string>}
     */
    private function answer(): array
    {
        if (Lock::isHeld($this->lockPath())) {
            return ['status' => self::WORKING, 'lines' => ['a worker is running']];
        }

        $lines = [];
        $say = static function (string $line, bool $problem = false) use (&$lines): void {
            $lines[] = $line;
        };
        $plan = $this->scheduler->plan($this->profiles->all(), $this->now());
        $this->acknowledgeSkipped($plan, $say);
        $interrupted = $this->interrupted();
        $linked = $this->linkedRuns();

        $worker = $this->mode === 'worker' || ($this->mode === 'auto' && $this->launcher !== null);
        if ($worker && $this->launcher !== null) {
            if ($interrupted === [] && $plan->due === []) {
                return ['status' => self::IDLE, 'lines' => $lines];
            }
            $why = $this->launcher->start();
            if ($why === null) {
                $this->audit->record('trigger.worker', self::USER, '', ['interrupted' => count($interrupted), 'due' => count($plan->due)]);

                return ['status' => self::STARTED, 'lines' => [...$lines, 'a worker was started: ' . count($plan->due) . ' due, ' . count($interrupted) . ' interrupted']];
            }
            if ($why === 'a worker was started a moment ago') {
                return ['status' => self::WORKING, 'lines' => [...$lines, $why]];
            }
            if ($this->mode === 'worker') {
                $this->audit->record('trigger.worker.failed', self::USER, '', ['why' => $why]);

                return ['status' => self::IDLE, 'lines' => [...$lines, 'no worker could be started: ' . $why]];
            }
            $lines[] = 'no worker could be started (' . $why . '); working in ticks';
        } elseif ($this->mode === 'worker') {
            return ['status' => self::IDLE, 'lines' => [...$lines, 'no worker can be started on this host']];
        }

        if ($interrupted === [] && $linked === [] && $plan->due === []) {
            return ['status' => self::IDLE, 'lines' => $lines];
        }

        return $this->tick($plan, $interrupted, $linked, $lines);
    }

    /**
     * One tick of work in the request.
     *
     * @param list<Run>    $interrupted
     * @param list<Run>    $linked      runs that the schedule started and that are going
     * @param list<string> $lines
     *
     * @return array{status: string, lines: list<string>}
     */
    private function tick(SchedulePlan $plan, array $interrupted, array $linked, array $lines): array
    {
        $lock = Lock::tryAcquire($this->lockPath(), 'trigger (tick), pid ' . getmypid());
        if ($lock === null) {
            return ['status' => self::WORKING, 'lines' => [...$lines, 'another call is working']];
        }
        try {
            $run = $linked[0] ?? $interrupted[0] ?? null;
            if ($run === null) {
                try {
                    $run = $this->backups->startScheduled($plan->due[0]->profileId, snapshot: false);
                } catch (GuardFailed $e) {
                    return ['status' => self::WORKING, 'lines' => [...$lines, $e->getMessage()]];
                }
                $this->scheduler->started($plan->due[0]->profileId, $plan->due[0]->instant, $this->now(), $run->id);
                $this->audit->record('backup.started', self::USER, '', ['profile' => $plan->due[0]->profileId, 'run' => $run->id, 'source' => 'schedule', 'scheduled' => $plan->due[0]->instant->format('Y-m-d H:i T')]);
            }
            $profileId = is_string($run->state['profileId'] ?? null) ? $run->state['profileId'] : '';
            $instant = $this->scheduler->instantOfRun($profileId, $run->id);

            try {
                $after = $this->backups->tick($run->id);
            } catch (EngineException $e) {
                if ($e->isBusy()) {
                    return ['status' => self::WORKING, 'lines' => [...$lines, 'the backup is being worked on']];
                }
                throw $e;
            } catch (Throwable $e) {
                $done = [];
                $say = static function (string $line, bool $problem = false) use (&$lines): void {
                    $lines[] = $line;
                };
                $this->failed($run->id, $profileId, $instant, $e, $say, $done);

                return ['status' => $this->moreToDo() ? self::WORKING : self::IDLE, 'lines' => $lines];
            }

            if ($after->status === RunStatus::Finished) {
                $done = [];
                $say = static function (string $line, bool $problem = false) use (&$lines): void {
                    $lines[] = $line;
                };
                $this->succeeded($after, $profileId, $instant, $say, $done);

                return ['status' => $this->moreToDo() ? self::WORKING : self::IDLE, 'lines' => $lines];
            }

            return ['status' => self::WORKING, 'lines' => [...$lines, 'backing up "' . $profileId . '": ' . ($after->progress ?? 'working')]];
        } finally {
            $lock->release();
        }
    }

    /** Whether, with the schedule as it is now, something is due or going. */
    private function moreToDo(): bool
    {
        return $this->interrupted() !== [] || $this->linkedRuns() !== [] || $this->scheduler->plan($this->profiles->all(), $this->now())->due !== [];
    }

    // ----------------------------------------------------------------------------------------- the work

    /**
     * @param list<string> $done
     */
    private function continueRun(Run $run, Closure $say, array &$done): int
    {
        $profileId = is_string($run->state['profileId'] ?? null) ? $run->state['profileId'] : '';
        $say('Continuing the interrupted backup of "' . $profileId . '" (run ' . $run->id . ') ...', false);

        return $this->complete($run->id, $profileId, $this->scheduler->instantOfRun($profileId, $run->id), $say, $done);
    }

    /**
     * @param list<string> $done
     */
    private function runDue(DueRun $due, Closure $say, array &$done): int
    {
        $lock = Lock::tryAcquire($this->data . '/locks/backup.lock', 'trigger, backup of ' . $due->profileId);
        if ($lock === null) {
            $say('Another backup is running (' . (Lock::holder($this->data . '/locks/backup.lock') ?? 'holder unknown') . '); "' . $due->profileId . '" waits for the next call.', true);

            return self::STOP;
        }
        try {
            try {
                $run = $this->backups->startScheduled($due->profileId, snapshot: true);
            } catch (GuardFailed $e) {
                $say('"' . $due->profileId . '" waits: ' . $e->getMessage(), true);

                return self::STOP;
            }
            $this->scheduler->started($due->profileId, $due->instant, $this->now(), $run->id);
            $this->audit->record('backup.started', self::USER, '', ['profile' => $due->profileId, 'run' => $run->id, 'source' => 'schedule', 'scheduled' => $due->instant->format('Y-m-d H:i T'), 'attempt' => $due->attempt]);
            $say('Backing up "' . $due->profileId . '" (scheduled for ' . $due->instant->format('Y-m-d H:i T') . ($due->attempt > 1 ? ', attempt ' . $due->attempt : '') . ') ...', false);

            return $this->complete($run->id, $due->profileId, $due->instant, $say, $done);
        } finally {
            $lock->release();
        }
    }

    /**
     * Works on the run to its end and tells the scheduler how it went.
     *
     * @param list<string> $done
     */
    private function complete(string $runId, string $profileId, ?DateTimeImmutable $instant, Closure $say, array &$done): int
    {
        try {
            $run = $this->backups->complete($runId);
        } catch (EngineException $e) {
            if ($e->isBusy()) {
                $say('The backup of "' . $profileId . '" is being worked on by another process.', false);

                return 0;
            }

            return $this->failed($runId, $profileId, $instant, $e, $say, $done);
        } catch (Throwable $e) {
            return $this->failed($runId, $profileId, $instant, $e, $say, $done);
        }

        return $this->succeeded($run, $profileId, $instant, $say, $done);
    }

    /**
     * @param list<string> $done
     */
    private function succeeded(Run $run, string $profileId, ?DateTimeImmutable $instant, Closure $say, array &$done): int
    {
        $result = $this->backups->result($run);
        if ($result === null) {
            return $this->failed($run->id, $profileId, $instant, new \RuntimeException('The run ended without a result.'), $say, $done);
        }
        $this->backups->dismiss($run->id);
        $this->forgetFailedRuns($profileId);
        if ($instant !== null) {
            $this->scheduler->succeeded($profileId, $instant);
        }
        $this->retentionAfter($profileId, $say);
        $name = (string) ($result['backup'] ?? '?');
        $this->audit->record('backup.finished', self::USER, '', ['run' => $run->id, 'profile' => $profileId, 'backup' => $name, 'source' => $instant === null ? 'continued' : 'schedule']);
        $say(sprintf('Backup %s: %d file(s), status %s.', $name, (int) ($result['files'] ?? 0), (string) ($result['status'] ?? '?')), false);
        $done[] = 'backed up "' . $profileId . '"';

        return 0;
    }

    /**
     * @param list<string> $done
     */
    private function failed(string $runId, string $profileId, ?DateTimeImmutable $instant, Throwable $error, Closure $say, array &$done): int
    {
        $retry = $instant !== null && $this->scheduler->failed($profileId, $instant, $this->now());
        $this->audit->record('backup.failed', self::USER, '', ['run' => $runId, 'profile' => $profileId, 'source' => $instant === null ? 'continued' : 'schedule', 'retry' => $retry]);
        $say('The backup of "' . $profileId . '" failed: ' . ($error->getMessage() !== '' ? $error->getMessage() : $error::class)
            . ($retry ? ' It is tried again in ' . Scheduler::RETRY_MINUTES . ' minutes, if the next call is by then.' : ($instant !== null ? ' No more attempts for this scheduled time.' : '')), true);
        $done[] = 'failed "' . $profileId . '"';

        return $error instanceof GuardFailed ? 2 : 3;
    }

    /**
     * Retention after a successful backup (docs/PHASE5.md 3.4). It must never fail the backup that has just been made.
     */
    private function retentionAfter(string $profileId, Closure $say): void
    {
        if ($this->retention === null) {
            return;
        }
        try {
            $profile = $this->profiles->load($profileId);
            $plan = $this->retention->automatic($profile);
            if ($plan !== null) {
                $this->reportRetention($profileId, $plan, $say);
            } elseif ($profile->retention !== null) {
                $say('Retention of "' . $profileId . '" waits for your go-ahead: its rules would delete ' . ($this->retention->waiting($profile) ?? 0) . ' backups (run "threadsafe retention ' . $profileId . ' --dry-run" to see them, "threadsafe retention ' . $profileId . '" to go ahead).', true);
            }
        } catch (Throwable $e) {
            $say('Retention of "' . $profileId . '" could not be applied: ' . $e->getMessage(), true);
        }
    }

    /**
     * Retention once an hour for every profile that has rules.
     *
     * @param list<string> $done
     */
    private function hourlyRetention(Closure $say, array &$done): void
    {
        if ($this->retention === null) {
            return;
        }
        try {
            foreach ($this->retention->hourly() as $profileId => $plan) {
                $this->reportRetention($profileId, $plan, $say);
                if ($plan->delete !== []) {
                    $done[] = 'retention of "' . $profileId . '"';
                }
            }
        } catch (Throwable $e) {
            $say('Retention could not be applied: ' . $e->getMessage(), true);
        }
    }

    private function reportRetention(string $profileId, \ThreadSafe\Retention\RetentionPlan $plan, Closure $say): void
    {
        if ($plan->blocked) {
            $say('Retention of "' . $profileId . '": ' . implode(' ', $plan->notes), true);

            return;
        }
        $count = count($plan->delete) + count($plan->leftovers);
        if ($count > 0) {
            $say(sprintf('Retention of "%s": %d backup%s deleted%s, %s freed.', $profileId, count($plan->delete), count($plan->delete) === 1 ? '' : 's', $plan->leftovers === [] ? '' : ' and ' . count($plan->leftovers) . ' leftover' . (count($plan->leftovers) === 1 ? '' : 's') . ' removed', SpaceCheck::format($plan->freed())), false);
        }
    }

    /** The failed records of the profile's earlier attempts are no longer news once a backup has succeeded. */
    private function forgetFailedRuns(string $profileId): void
    {
        foreach ($this->backups->runs() as $run) {
            if ($run->status === RunStatus::Failed && ($run->state['profileId'] ?? null) === $profileId) {
                $this->backups->dismiss($run->id);
            }
        }
    }

    private function acknowledgeSkipped(SchedulePlan $plan, Closure $say): void
    {
        foreach ($plan->skipped as $skipped) {
            $this->scheduler->skipped($skipped->profileId, $skipped->instant);
            $this->audit->record('schedule.skipped', self::USER, '', ['profile' => $skipped->profileId, 'scheduled' => $skipped->instant->format('Y-m-d H:i T'), 'reason' => $skipped->reason]);
            $say('The scheduled backup of "' . $skipped->profileId . '" for ' . $skipped->instant->format('Y-m-d H:i T') . ' was given up: ' . $skipped->reason . '.', true);
        }
    }

    // ------------------------------------------------------------------------------------------ the runs

    /**
     * The backup runs that should be going and that nobody is working on, oldest first.
     *
     * @return list<Run>
     */
    private function interrupted(): array
    {
        $runs = array_filter($this->backups->runs(), fn (Run $run): bool => $run->status === RunStatus::Running && $this->backups->state($run) === 'interrupted');

        return array_reverse(array_values($runs));
    }

    /**
     * The runs that the schedule started and that have not ended, whether or not a tick was recent.
     *
     * @return list<Run>
     */
    private function linkedRuns(): array
    {
        $linked = [];
        foreach ($this->profiles->all() as $profile) {
            $runId = $profile->schedule === null ? null : $this->scheduler->runOf($profile->id);
            if ($runId === null) {
                continue;
            }
            try {
                $run = $this->backups->find($runId);
            } catch (EngineException) {
                continue;
            }
            if ($run->status === RunStatus::Running) {
                $linked[] = $run;
            }
        }

        return $linked;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . ($this->clock)());
    }

    private function lockPath(): string
    {
        return $this->data . '/locks/cron.lock';
    }
}
