<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\Exception\StepFailed;
use ThreadSafe\Core\Version;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\DatabaseError;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\Task;
use ThreadSafe\Engine\TickOutcome;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\CheckLevel;
use ThreadSafe\Profile\ConnectionTest;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\ProfileResolver;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Storage\LocalPostproc;
use ThreadSafe\Storage\LocalStorage;
use ThreadSafe\Storage\SpaceCheck;

/**
 * The backup of one site profile as a task that works in ticks (see {@see Task}).
 *
 * Phases: **init** (test the profile, make the storage ready, name the backup) -> **scan** (list the files in the order of
 * the archive) -> space check -> **dump** (the database into SQL files in the work directory) -> **pack** (files, then dump
 * files, into archive parts) -> **finale** (manifest into the last part, sidecar file, confirm, clean up). A phase that is
 * not finished when the budget runs out continues in the next tick; with an unlimited budget (the command line) one tick
 * does everything, which is what lets the database dump run inside one consistent snapshot.
 *
 * The state holds no secret: the profile is loaded from its store in every tick and must not change while the run lasts.
 * A run that fails is cleaned up by {@see abandon()}; one whose process dies is continued from its saved state.
 */
final class BackupRun implements Task
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param Closure(): int|null $clock the current time as a Unix timestamp (for tests)
     * @param SpaceCheck|null     $space the free-space check (for tests)
     */
    public function __construct(
        private readonly ProfileStore $profiles,
        private readonly PluginRegistry $plugins,
        private readonly ?string $dataDirectory = null,
        ?Closure $clock = null,
        private readonly int $scanBatch = 500,
        private readonly ?SpaceCheck $space = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    public function id(): string
    {
        return 'backup';
    }

    /**
     * @param array<string, mixed> $parameters `profile` (id), `force`, `snapshot` (dump inside one consistent snapshot),
     *                                         `web` (started by the web interface), `source` (`manual` or `schedule`), `partSize` and
     *                                         `compression` (override the profile)
     */
    public function initialState(array $parameters): array
    {
        $profile = $parameters['profile'] ?? null;
        if (!is_string($profile) || !ProfileStore::validId($profile)) {
            throw new InvalidArgumentException('A backup needs the id of a profile.');
        }
        $partSize = $parameters['partSize'] ?? null;
        $compression = $parameters['compression'] ?? null;
        if (($partSize !== null && (!is_int($partSize) || $partSize < 1048576)) || ($compression !== null && (!is_int($compression) || $compression < 0 || $compression > 9))) {
            throw new InvalidArgumentException('The part size must be at least 1 MiB and the compression 0-9.');
        }

        $state = new BackupState($profile, ($parameters['force'] ?? false) === true, ($parameters['snapshot'] ?? false) === true, $partSize, $compression);
        $state->web = ($parameters['web'] ?? false) === true;
        $state->source = ($parameters['source'] ?? 'manual') === 'schedule' ? 'schedule' : 'manual';

        return $state->toArray();
    }

    public function tick(array $state, Budget $budget): TickOutcome
    {
        $s = BackupState::fromArray($state);
        $context = $this->context($s);

        if ($s->phase === BackupState::INIT) {
            $context = $this->init($s, $context);
            $s->phase = BackupState::SCAN;
        }
        if ($s->phase === BackupState::SCAN && $this->scan($s, $context, $budget)) {
            $this->checkSpace($s, $context);
            $s->phase = $context->resolved->database === null ? BackupState::PACK : BackupState::DUMP;
        }
        if ($s->phase === BackupState::DUMP && $this->dump($s, $context, $budget)) {
            $s->phase = BackupState::PACK;
        }
        if ($s->phase === BackupState::PACK && $this->pack($s, $context, $budget)) {
            $s->phase = BackupState::FINALE;
        }
        if ($s->phase === BackupState::FINALE) {
            $this->finale($s, $context);
            $s->phase = BackupState::DONE;
        }

        return new TickOutcome($s->toArray(), $s->phase === BackupState::DONE, $this->progress($s));
    }

    /**
     * Removes everything a failed or abandoned run left in the storage: unfinished parts, finished parts, the work directory.
     *
     * @param array<string, mixed> $state the state of the run
     */
    public function abandon(array $state): void
    {
        try {
            $s = BackupState::fromArray($state);
            $name = BackupName::parse($s->backup);
            if ($name !== null) {
                (new LocalStorage($this->profiles->load($s->profileId)->storage))->removeBackup($name);
            }
        } catch (\Throwable) {
            // nothing to clean up, or nothing that can be found any more
        }
    }

    // ------------------------------------------------------------------------------------------------ context

    private function context(BackupState $s): BackupContext
    {
        try {
            $profile = $this->profiles->load($s->profileId);
            if ($s->profileHash !== '' && $s->profileHash !== self::hash($profile->toArray())) {   // (the schedule and the retention are no part of it)
                throw new GuardFailed('The profile was changed while the backup was running; start a new backup.');
            }
            $resolved = (new ProfileResolver($this->plugins, $this->dataDirectory))->resolve($profile);
        } catch (ProfileException $e) {
            throw new GuardFailed($e->getMessage(), 0, $e);
        }

        return new BackupContext(
            $resolved,
            new LocalStorage($profile->storage),
            BackupName::parse($s->backup),
            $s->partSize ?? $profile->partSize,
            $s->compression ?? $profile->compression,
        );
    }

    /**
     * @param array<string, mixed> $profile
     */
    private static function hash(array $profile): string
    {
        // changing when the profile is backed up, or what is kept afterwards, must not fail a run that is going
        unset($profile['schedule'], $profile['retention']);

        return hash('sha256', (string) json_encode($profile));
    }

    // ---------------------------------------------------------------------------------------------- init

    private function init(BackupState $s, BackupContext $context): BackupContext
    {
        $profile = $context->resolved->profile;
        $report = (new ConnectionTest($this->plugins, $this->dataDirectory))->run($profile);
        if (!$report->passed()) {
            throw new GuardFailed("The profile \"{$profile->id}\" cannot be backed up:\n  " . implode("\n  ", $report->messages(CheckLevel::Failure)));
        }
        foreach ($report->checks as $check) {
            if ($check->level === CheckLevel::Warning && $check->subject !== 'Database objects') {
                $s->warn($check->subject . ': ' . $check->message);
            }
        }

        $context->storage->prepare();
        $name = $context->storage->newName($profile->id, new DateTimeImmutable('@' . ($this->clock)()));
        $s->backup = $name->base();
        $s->profileHash = self::hash($profile->toArray());
        $s->started = ($this->clock)();

        $context = new BackupContext($context->resolved, $context->storage, $name, $context->partSize, $context->compression);
        $work = $context->workDirectory();
        if (!is_dir($work . '/db') && !@mkdir($work . '/db', 0700)) {
            throw new StepFailed("Cannot create the work directory \"$work/db\".");
        }

        return $context;
    }

    // ---------------------------------------------------------------------------------------------- scan

    /**
     * @return bool the whole site is listed
     */
    private function scan(BackupState $s, BackupContext $context, Budget $budget): bool
    {
        $scanner = new Scanner($context->resolved->root, $context->resolved->filters());
        $state = $s->scan ?? $scanner->start();
        $list = $context->workDirectory() . '/files.jsonl';

        do {
            $batch = $scanner->next($state, $budget, $this->scanBatch);
            $s->listSize = FileList::append($list, $s->listSize, $batch->items);
            foreach ($batch->items as $item) {
                if ($item->isDirectory) {
                    ++$s->scanDirectories;
                } else {
                    ++$s->scanFiles;
                    $s->scanBytes += $item->size;
                }
            }
            $s->warnAll($batch->warnings);
            $state = $batch->state;
        } while (!$batch->done && !$budget->exhausted());

        $s->scan = $state;

        return $batch->done;
    }

    /**
     * @throws GuardFailed there is not enough room for the backup
     */
    private function checkSpace(BackupState $s, BackupContext $context): void
    {
        $databaseBytes = 0;
        $db = $context->db();
        if ($db !== null) {
            try {
                $databaseBytes = (int) $db->scalar("SELECT IFNULL(SUM(DATA_LENGTH + INDEX_LENGTH), 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
            } catch (DatabaseError) {
                $databaseBytes = 0;
            }
        }
        $required = SpaceCheck::required($this->lastBackupSize($context), $s->scanBytes, $databaseBytes);
        $result = ($this->space ?? new SpaceCheck())->check($context->storage->directory(), $required, $s->force);
        if (!$result->allowed) {
            throw new GuardFailed($result->refusal());
        }
        if ($result->warning !== null) {
            $s->warn($result->warning);
        }
    }

    /** The size of the newest usable backup of the profile, null if there is none. */
    private function lastBackupSize(BackupContext $context): ?int
    {
        foreach ($context->storage->names($context->resolved->profile->id) as $name) {
            if ($context->name !== null && $name->base() === $context->name->base()) {
                continue;
            }
            try {
                $sidecar = $context->storage->readSidecar($name);
            } catch (\ThreadSafe\Storage\StorageException) {
                continue;
            }
            if ($sidecar->status !== BackupStatus::Faulty) {
                return $sidecar->size();
            }
        }

        return null;
    }

    // ----------------------------------------------------------------------------------------------- dump

    /**
     * @return bool the database is dumped
     */
    private function dump(BackupState $s, BackupContext $context, Budget $budget): bool
    {
        $db = $context->db();
        if ($db === null) {
            return true;
        }
        $dumper = new Dumper($db);
        $state = $s->dump ?? $dumper->initialState([
            'directory' => $context->workDirectory() . '/db',
            'snapshot' => $s->snapshot,
            'structureOnly' => $context->resolved->structureOnly,
        ]);
        $outcome = $dumper->tick($state, $budget);
        $s->dump = $outcome->state;

        return $outcome->finished;
    }

    /**
     * The dump files in the order they go into the archive.
     *
     * @return list<array{file: string, bytes: int}>
     */
    private function databaseFiles(BackupState $s): array
    {
        if ($s->dump === null) {
            return [];
        }
        $dump = DumpState::fromArray($s->dump);

        return [
            ...array_map(static fn (array $t): array => ['file' => $t['file'], 'bytes' => $t['bytes']], $dump->done),
            ...$dump->objectFiles,
        ];
    }

    // ----------------------------------------------------------------------------------------------- pack

    private function packer(BackupContext $context): ArchivePacker
    {
        return new ArchivePacker(
            $context->storage,
            $context->backupName(),
            $context->resolved->root,
            $context->workDirectory(),
            $context->compression,
            $context->partSize,
        );
    }

    /**
     * @return bool everything is in the archive (only the manifest is missing)
     */
    private function pack(BackupState $s, BackupContext $context, Budget $budget): bool
    {
        $pack = $s->pack !== null ? PackState::fromArray($s->pack) : new PackState();
        $done = $this->packer($context)->pack($pack, $budget, $this->databaseFiles($s));
        $s->pack = $pack->toArray();

        return $done;
    }

    // --------------------------------------------------------------------------------------------- finale

    private function finale(BackupState $s, BackupContext $context): void
    {
        $name = $context->backupName();
        $resolved = $context->resolved;
        $pack = PackState::fromArray($s->pack ?? (new PackState())->toArray());
        $dump = $s->dump !== null ? DumpState::fromArray($s->dump) : null;

        $s->warnAll($pack->warnings);
        $unsupported = [];
        $consistency = 'none';
        $tables = $rows = $databaseBytes = 0;
        if ($dump !== null) {
            $s->warnAll($dump->warnings);
            $unsupported = $dump->unsupported;
            $consistency = $dump->consistency;
            $tables = count($dump->done);
            $rows = $dump->rows;
            $databaseBytes = $dump->bytes;
        }
        $finished = new DateTimeImmutable('@' . ($this->clock)());
        $started = new DateTimeImmutable('@' . $s->started);
        $warnings = $s->warnings;
        if ($s->warningsDropped > 0) {
            $warnings[] = "{$s->warningsDropped} more warnings are not listed.";
        }
        $db = $context->db();

        $base = new Manifest(
            $name->base(),
            Version::CURRENT,
            $resolved->profile->id,
            $resolved->profile->name,
            $resolved->profile->type,
            ['name' => $resolved->site->application, 'version' => $resolved->site->version],
            PHP_VERSION,
            $this->databaseFacts($db),
            $consistency,
            $resolved->type->manifestExtras($resolved->site, $db),
            ['excludePaths' => $resolved->excludePaths, 'structureOnlyTables' => $resolved->structureOnly, 'literalExcludes' => $resolved->literalExcludes],
            [
                'files' => $pack->files,
                'directories' => $pack->directories,
                'fileBytes' => $pack->fileBytes,
                'tables' => $tables,
                'rows' => $rows,
                'databaseBytes' => $databaseBytes,
            ],
            [],
            $started,
            $finished,
            $warnings,
            $unsupported,
        );

        $records = $this->packer($context)->finish($pack, $base);
        $s->pack = $pack->toArray();

        $manifest = $base->withParts(array_map(static fn (PartRecord $p): array => ['file' => $p->file, 'entries' => $p->entries], $records));
        $status = $warnings === [] && $unsupported === [] ? BackupStatus::Ok : BackupStatus::OkWithWarnings;
        $sidecar = new Sidecar($manifest, $records, $status, [], false, $s->source);
        $context->storage->writeSidecar($name, $sidecar);

        $transfer = (new LocalPostproc())->store($name, $sidecar, $context->storage);
        if (!$transfer->success) {
            throw new StepFailed($transfer->message);
        }
        $context->storage->removeWorkDirectory($name);

        $s->result = [
            'backup' => $name->base(),
            'parts' => array_map(static fn (PartRecord $p): string => $p->file, $records),
            'size' => $sidecar->size(),
            'status' => $status->value,
            'warnings' => count($warnings),
            'files' => $pack->files,
            'directories' => $pack->directories,
            'tables' => $tables,
            'rows' => $rows,
            'consistency' => $consistency,
            'duration' => $finished->getTimestamp() - $started->getTimestamp(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseFacts(?Connection $db): array
    {
        if ($db === null) {
            return [];
        }
        $facts = ['server' => $db->server->type, 'version' => $db->server->version, 'driver' => $db->driverName(), 'name' => $db->settings->name, 'prefix' => $db->settings->prefix];
        try {
            $facts['charset'] = (string) $db->scalar('SELECT @@character_set_database');
            $facts['collations'] = array_map(
                static fn (array $row): string => (string) $row['c'],
                $db->select('SELECT DISTINCT TABLE_COLLATION AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_COLLATION IS NOT NULL ORDER BY 1'),
            );
        } catch (DatabaseError) {
            // the facts above are enough
        }

        return $facts;
    }

    private function progress(BackupState $s): string
    {
        return match ($s->phase) {
            BackupState::SCAN => sprintf('scanning the site (%d files and %d directories so far)', $s->scanFiles, $s->scanDirectories),
            BackupState::DUMP => 'dumping the database' . ($s->dump !== null ? ' (' . count(DumpState::fromArray($s->dump)->done) . ' tables done)' : ''),
            BackupState::PACK => sprintf('packing files into the archive (%d files so far)', $s->pack !== null ? PackState::fromArray($s->pack)->files : 0),
            BackupState::FINALE => 'finishing the archive',
            BackupState::DONE => 'backup ' . $s->backup . ' is complete',
            default => 'starting',
        };
    }
}
