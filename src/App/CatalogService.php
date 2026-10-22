<?php

declare(strict_types=1);

namespace ThreadSafe\App;

use Closure;
use Throwable;
use ThreadSafe\Backup\VerifyRun;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\EngineException;
use ThreadSafe\Engine\Executor;
use ThreadSafe\Engine\Lock;
use ThreadSafe\Engine\Run;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Storage\BackupCatalog;
use ThreadSafe\Storage\BackupEntry;
use ThreadSafe\Storage\LocalStorage;
use ThreadSafe\Storage\StorageException;

/**
 * The backups that exist (docs/PHASE4.md 3.7): listing them from the sidecar files, looking at one, deleting, and checking that
 * one is intact - the check as a task in ticks, because reading a multi-gigabyte archive does not fit into one web request.
 */
final class CatalogService
{
    /** A check whose last tick is older than this (seconds) is taken for interrupted, however long a tick may last. */
    public const int GRACE = 60;

    private const int KEEP = 86400;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param Closure(): Budget|null $budgets the budget of a tick; default: a share of the request time limit
     * @param Closure(): int|null    $clock   the time in seconds (for tests)
     */
    public function __construct(
        private readonly ProfileStore $profiles,
        private readonly RunStore $runs,
        private readonly ?Closure $budgets = null,
        ?Closure $clock = null,
        private readonly int $grace = self::GRACE,
    ) {
        $this->clock = $clock ?? time(...);
    }

    // ------------------------------------------------------------------------------------------------- the backups

    /**
     * The backups of a profile, newest first. A sidecar file that is missing is made again (a listing then writes).
     *
     * @return list<BackupEntry>
     *
     * @throws ProfileException there is no such profile
     */
    public function backups(string $profileId): array
    {
        return (new BackupCatalog($this->storage($profileId)))->entries($profileId);
    }

    /**
     * @throws ProfileException there is no such profile
     * @throws StorageException there is no such backup of that profile
     */
    public function find(string $profileId, string $backup): BackupEntry
    {
        return (new BackupCatalog($this->storage($profileId)))->find($profileId, $backup);
    }

    /**
     * One part of a backup, for a download: where it is, how big it is and its SHA-256 as recorded. Only a file the record of the
     * backup names can be asked for.
     *
     * @return array{path: string, size: int, sha256: string}
     *
     * @throws ProfileException|StorageException there is no such profile, backup or part, or the file is not there
     */
    public function part(string $profileId, string $backup, string $file): array
    {
        $entry = $this->find($profileId, $backup);
        foreach ($entry->sidecar->parts ?? [] as $part) {
            if ($part->file === $file) {
                $path = $this->storage($profileId)->directory() . '/' . $part->file;
                if (!is_file($path)) {
                    throw new StorageException("The part \"$file\" is not in the storage directory.");
                }

                return ['path' => $path, 'size' => (int) filesize($path), 'sha256' => $part->sha256];
            }
        }

        throw new StorageException("\"$file\" is not a part of the backup \"$backup\".");
    }

    /**
     * The storage directory of a profile, as the person sees it on the server (for the advice to fetch large files by SFTP).
     *
     * @throws ProfileException there is no such profile
     */
    public function storageDirectory(string $profileId): string
    {
        return $this->storage($profileId)->directory();
    }

    /**
     * Deletes a backup: its parts, its sidecar file and any work directory.
     *
     * @throws GuardFailed a run is writing or checking that backup
     * @throws ProfileException|StorageException there is no such profile or backup
     */
    public function delete(string $profileId, string $backup): void
    {
        $entry = $this->find($profileId, $backup);
        $this->assertNotInUse($entry->name->base());
        $this->storage($profileId)->removeBackup($entry->name);
    }

    /**
     * Pins a backup (retention must not delete it, and it does not count towards `keep`) or lets it go again. The mark lives in the
     * sidecar file; a sidecar file that is made again from the archive has lost it.
     *
     * @throws GuardFailed the backup has no usable sidecar file to carry the mark
     * @throws ProfileException|StorageException there is no such profile or backup
     */
    public function pin(string $profileId, string $backup, bool $pinned): void
    {
        $entry = $this->find($profileId, $backup);
        if ($entry->sidecar === null) {
            throw new GuardFailed('This backup has no usable sidecar file, so it cannot be pinned.');
        }
        $this->storage($profileId)->writeSidecar($entry->name, $entry->sidecar->withPinned($pinned));
    }

    /**
     * Deletes every backup of a profile (the option of removing a profile together with its backups).
     *
     * @return int how many backups were deleted
     *
     * @throws GuardFailed a run is writing or checking one of them; nothing is deleted then
     */
    public function deleteAll(string $profileId): int
    {
        $entries = $this->backups($profileId);
        foreach ($entries as $entry) {
            $this->assertNotInUse($entry->name->base());
        }
        $storage = $this->storage($profileId);
        foreach ($entries as $entry) {
            $storage->removeBackup($entry->name);
        }

        return count($entries);
    }

    /**
     * @throws GuardFailed
     */
    private function assertNotInUse(string $base): void
    {
        foreach ($this->runs->all() as $run) {
            if ($run->status !== RunStatus::Running || !$this->isActive($run)) {
                continue;
            }
            if (($run->state['backup'] ?? null) === $base) {
                throw new GuardFailed('The backup is in use by a ' . ($run->task === 'backup' ? 'backup that is still being made' : 'check that is running') . '; wait for it to end first.');
            }
        }
    }

    // ------------------------------------------------------------------------------------------------- checking

    public function task(): VerifyRun
    {
        return new VerifyRun(function (string $profileId): LocalStorage {
            try {
                return $this->storage($profileId);
            } catch (ProfileException $e) {
                throw new StorageException($e->getMessage(), 0, $e);
            }
        });
    }

    public function executor(): Executor
    {
        return new Executor($this->runs, $this->task(), $this->budgets ?? Budget::forThisProcess(...));
    }

    /**
     * @return list<Run> the checks on record, newest first
     */
    public function verifications(): array
    {
        return $this->runs->all('verify');
    }

    /**
     * @throws EngineException there is no such run, or it is not a check
     */
    public function findVerification(string $runId): Run
    {
        $run = $this->runs->load($runId);
        if ($run->task !== 'verify') {
            throw new EngineException("Run \"$runId\" is not a check.");
        }

        return $run;
    }

    /**
     * Starts a check of a backup. Nothing is read yet; the ticks follow.
     *
     * @throws GuardFailed another check is going, or the backup cannot be checked
     * @throws ProfileException|StorageException there is no such profile or backup
     */
    public function startVerify(string $profileId, string $backup, bool $full = true): Run
    {
        $entry = $this->find($profileId, $backup);
        if ($entry->condition === BackupEntry::UNFINISHED || $entry->condition === BackupEntry::UNKNOWN) {
            throw new GuardFailed('This backup cannot be checked: ' . ($entry->problem ?? 'nothing usable found') . '.');
        }
        foreach ($this->verifications() as $run) {
            if ($run->status === RunStatus::Running && $this->isActive($run)) {
                throw new GuardFailed('Another check is running. One check at a time.');
            }
        }
        $this->prune();

        return $this->executor()->start(['profile' => $profileId, 'backup' => $entry->name->base(), 'full' => $full]);
    }

    /**
     * Does one tick of a check.
     *
     * @throws EngineException the run is being worked on by another process right now ({@see EngineException::isBusy()}), or is not a check
     * @throws Throwable       what the tick threw
     */
    public function tick(string $runId): Run
    {
        $this->findVerification($runId);

        return $this->executor()->execute($runId, 1);
    }

    /**
     * What a check is doing: `active`, `interrupted`, `finished` or `failed`.
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

        // a check is always started by the web interface, and so is a backup that says so: one process per tick, so such a run counts
        // as active while its last tick is recent (the same rule as in BackupService)
        $web = $run->task === 'verify' || ($run->state['web'] ?? false) === true;

        return $web && ($this->clock)() - $run->updated < $this->grace;
    }

    /**
     * Removes the record of a check (it has ended, or it is given up).
     *
     * @throws GuardFailed a process is working on it right now
     * @throws EngineException there is no such run
     */
    public function discard(string $runId): void
    {
        $this->findVerification($runId);
        $lock = Lock::tryAcquire($this->runs->lockPath($runId), 'discarding check ' . $runId);
        if ($lock === null) {
            throw new GuardFailed('The check is being worked on right now; try again in a moment.');
        }
        try {
            $this->runs->delete($runId);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{percent: int|null, label: string}
     */
    public function progress(Run $run): array
    {
        return ['percent' => VerifyRun::percent($run->state), 'label' => $run->progress ?? 'starting'];
    }

    /**
     * The report of a finished check.
     *
     * @return array<string, mixed>|null
     */
    public function result(Run $run): ?array
    {
        $result = $run->state['result'] ?? null;

        return is_array($result) ? $result : null;
    }

    /** Removes the records of old finished and failed checks. */
    public function prune(): void
    {
        $now = ($this->clock)();
        foreach ($this->verifications() as $run) {
            if ($run->status !== RunStatus::Running && $now - $run->updated > self::KEEP) {
                $this->runs->delete($run->id);
            }
        }
    }

    // ------------------------------------------------------------------------------------------------- helpers

    /**
     * @throws ProfileException
     */
    private function storage(string $profileId): LocalStorage
    {
        return new LocalStorage($this->profiles->load($profileId)->storage);
    }
}
