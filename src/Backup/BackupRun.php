<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\Exception\StepFailed;
use ThreadSafe\Core\Version;
use ThreadSafe\Crypto\CryptoException;
use ThreadSafe\Crypto\Passphrases;
use ThreadSafe\Crypto\Recipient;
use ThreadSafe\Crypto\ScryptRecipient;
use ThreadSafe\Crypto\X25519Recipient;
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
use ThreadSafe\Profile\SiteProfile;
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
 * **Encrypted backups** (the profile has public keys, or the run has a passphrase; docs/PHASE5.md 3.5) write each part into the work
 * directory and seal it into the `age` format (`<part>.zip.age`) as soon as it is closed, in ticks ({@see PartSealer}); the last part
 * is closed in the phase **close** (with the manifest) and sealed in **seal**; the sidecar file is the minimal one.
 *
 * The state holds no secret, except for an encrypted backup: its file key (while a part is being sealed) and a passphrase typed for
 * this run, both in the 0600 file of the run, blanked when the run is done. The profile is loaded from its store in every tick and
 * must not change while the run lasts. A run that fails is cleaned up by {@see abandon()}; one whose process dies is continued from its
 * saved state.
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
        $passphrase = $parameters['passphrase'] ?? '';
        if (!is_string($passphrase)) {
            throw new InvalidArgumentException('The passphrase must be text.');
        }
        if ($passphrase !== '' && ($weak = Passphrases::weakness($passphrase)) !== null) {
            throw new InvalidArgumentException($weak);
        }
        $state->passphrase = $passphrase;
        $state->source = ($parameters['source'] ?? 'manual') === 'schedule' ? 'schedule' : 'manual';

        return $state->toArray();
    }

    public function tick(array $state, Budget $budget): TickOutcome
    {
        $s = BackupState::fromArray($state);
        $context = $this->context($s);

        if ($s->phase === BackupState::INIT) {
            $this->init($s, $context);
            $s->phase = BackupState::SCAN;

            // the tick ends here: the name of the backup and its work directory are in the saved state from now on, so a run that dies later
            // (a host that kills a long process) is continued into the same backup, and abandon() finds what it left
            return new TickOutcome($s->toArray(), false, $this->progress($s));
        }
        if ($s->phase === BackupState::SCAN && $this->scan($s, $context, $budget)) {
            $this->checkSpace($s, $context);
            $s->phase = $context->resolved->database === null ? BackupState::PACK : BackupState::DUMP;
        }
        if ($s->phase === BackupState::DUMP && $this->dump($s, $context, $budget)) {
            $s->phase = BackupState::PACK;
        }
        if ($s->phase === BackupState::PACK && $this->sealPending($s, $context, $budget) && $this->pack($s, $context, $budget)) {
            $s->phase = $context->encrypted() ? BackupState::CLOSE : BackupState::FINALE;
        }
        if ($s->phase === BackupState::CLOSE) {
            $this->close($s, $context);
            $s->phase = BackupState::SEAL;
        }
        if ($s->phase === BackupState::SEAL && $this->sealPending($s, $context, $budget)) {
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
            $this->recipients($s, $profile),
        );
    }

    /**
     * Whom the backup is encrypted for: the passphrase of this run if it has one (and then no one else: a file for a passphrase is for
     * the passphrase only), otherwise the public keys of the profile, otherwise nobody.
     *
     * @return list<Recipient>
     *
     * @throws GuardFailed encryption is wanted and this host cannot do it
     */
    private function recipients(BackupState $s, SiteProfile $profile): array
    {
        try {
            if ($s->passphrase !== '') {
                return [new ScryptRecipient($s->passphrase)];
            }

            return array_map(static fn (string $key): Recipient => new X25519Recipient($key), $profile->encryption->recipients ?? []);
        } catch (CryptoException $e) {
            throw new GuardFailed($e->getMessage(), 0, $e);
        }
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

    private function init(BackupState $s, BackupContext $context): void
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

        $context = new BackupContext($context->resolved, $context->storage, $name, $context->partSize, $context->compression, $context->recipients);
        $work = $context->workDirectory();
        if (!is_dir($work . '/db') && !@mkdir($work . '/db', 0700)) {
            throw new StepFailed("Cannot create the work directory \"$work/db\".");
        }
        if ($context->encrypted() && !is_dir($context->plainDirectory()) && !@mkdir($context->plainDirectory(), 0700)) {
            throw new StepFailed('Cannot create the work directory "' . $context->plainDirectory() . '".');
        }
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
        if ($context->encrypted()) {
            // a plain part waits next to the sealed ones: one part, or the whole archive if it is not split
            $required += $context->partSize ?? max(0, $required - $databaseBytes);
        }
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
            $context->encrypted() ? $context->plainDirectory() : null,
        );
    }

    /**
     * Seals the parts that are closed and not yet sealed (encrypted backups; for the others there is nothing to do).
     *
     * @return bool every closed part is sealed (false: the budget ran out; the job is in the state)
     */
    private function sealPending(BackupState $s, BackupContext $context, Budget $budget): bool
    {
        if (!$context->encrypted()) {
            return true;
        }
        $closed = PackState::fromArray($s->pack ?? (new PackState())->toArray())->closed;
        $sealer = new PartSealer($context->storage, $context->plainDirectory(), $context->recipients);
        try {
            while (count($s->sealed) < count($closed)) {
                $part = $closed[count($s->sealed)];
                $record = $sealer->seal($part['file'], $part['entries'], $s->seal, $budget);
                if ($record === null) {
                    return false;
                }
                $s->sealed[] = ['file' => $record->file, 'size' => $record->size, 'sha256' => $record->sha256, 'entries' => $record->entries];
                if ($budget->exhausted() && count($s->sealed) < count($closed)) {
                    return false;
                }
            }
        } catch (CryptoException $e) {
            throw new StepFailed('The backup cannot be encrypted: ' . $e->getMessage(), 0, $e);
        }

        return true;
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

    // --------------------------------------------------------------------------------------------- close and finale

    /**
     * What the manifest is made of, from the state of the run. With `$absorb` the warnings of the packing and the dump are taken into the
     * state (once: the first call of a run does it, a later call only reads).
     *
     * @return array{Manifest, BackupStatus, int, int, int, string, list<string>} the manifest without its parts, the status, the number of tables, rows, the bytes of the
     *                                                                           database, the consistency of the dump and the warnings
     */
    private function manifestBase(BackupState $s, BackupContext $context, PackState $pack, bool $absorb): array
    {
        $resolved = $context->resolved;
        $dump = $s->dump !== null ? DumpState::fromArray($s->dump) : null;

        if ($absorb) {
            $s->warnAll($pack->warnings);
        }
        $unsupported = [];
        $consistency = 'none';
        $tables = $rows = $databaseBytes = 0;
        if ($dump !== null) {
            if ($absorb) {
                $s->warnAll($dump->warnings);
            }
            $unsupported = $dump->unsupported;
            $consistency = $dump->consistency;
            $tables = count($dump->done);
            $rows = $dump->rows;
            $databaseBytes = $dump->bytes;
        }
        $finished = new DateTimeImmutable('@' . ($s->finished > 0 ? $s->finished : ($this->clock)()));
        $started = new DateTimeImmutable('@' . $s->started);
        $warnings = $s->warnings;
        if ($s->warningsDropped > 0) {
            $warnings[] = "{$s->warningsDropped} more warnings are not listed.";
        }
        $db = $context->db();

        $base = new Manifest(
            $context->backupName()->base(),
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

        return [$base, $warnings === [] && $unsupported === [] ? BackupStatus::Ok : BackupStatus::OkWithWarnings, $tables, $rows, $databaseBytes, $consistency, $warnings];
    }

    /**
     * Encrypted backups: the last part gets the manifest and is closed, so that it can be sealed like the others.
     */
    private function close(BackupState $s, BackupContext $context): void
    {
        $pack = PackState::fromArray($s->pack ?? (new PackState())->toArray());
        $s->finished = ($this->clock)();
        [$base] = $this->manifestBase($s, $context, $pack, absorb: true);
        $this->packer($context)->finish($pack, $base);
        $s->pack = $pack->toArray();
    }

    private function finale(BackupState $s, BackupContext $context): void
    {
        $name = $context->backupName();
        $pack = PackState::fromArray($s->pack ?? (new PackState())->toArray());
        $encrypted = $context->encrypted();

        [$base, $status, $tables, $rows, , $consistency, $warnings] = $this->manifestBase($s, $context, $pack, absorb: !$encrypted);
        if ($encrypted) {
            $records = array_map(static fn (array $p): PartRecord => new PartRecord($p['file'], $p['size'], $p['sha256'], $p['entries']), $s->sealed);
            $manifest = $base->withParts(array_map(static fn (PartRecord $p): array => ['file' => $p->file, 'entries' => $p->entries], $records))->minimal();
            $sidecar = new Sidecar($manifest, $records, $status, [], false, $s->source, $this->encryptionFacts($s, $context));
            $finished = $s->finished;
        } else {
            $finished = ($this->clock)();
            $s->finished = $finished;
            $records = $this->packer($context)->finish($pack, $base);
            $s->pack = $pack->toArray();
            $manifest = $base->withParts(array_map(static fn (PartRecord $p): array => ['file' => $p->file, 'entries' => $p->entries], $records));
            $sidecar = new Sidecar($manifest, $records, $status, [], false, $s->source);
        }
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
            'duration' => $finished - $s->started,
            'encrypted' => $encrypted,
        ];
    }

    /**
     * What the sidecar file of an encrypted backup says about the keys: the short forms of the public keys (to find the right secret key
     * again), and whether it is for a passphrase.
     *
     * @return array{format: string, recipients: list<string>, passphrase: bool}
     */
    private function encryptionFacts(BackupState $s, BackupContext $context): array
    {
        $keys = [];
        if ($s->passphrase === '') {
            foreach ($context->resolved->profile->encryption->recipients ?? [] as $key) {
                $keys[] = substr($key, 0, 12) . '...' . substr($key, -6);
            }
        }

        return ['format' => 'age', 'recipients' => $keys, 'passphrase' => $s->passphrase !== ''];
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

    private function packProgress(BackupState $s): string
    {
        $files = $s->pack !== null ? PackState::fromArray($s->pack)->files : 0;

        return sprintf('packing files into the archive (%d files so far%s)', $files, $s->sealed !== [] ? ', ' . count($s->sealed) . ' part(s) encrypted' : '');
    }

    private function progress(BackupState $s): string
    {
        return match ($s->phase) {
            BackupState::SCAN => sprintf('scanning the site (%d files and %d directories so far)', $s->scanFiles, $s->scanDirectories),
            BackupState::DUMP => 'dumping the database' . ($s->dump !== null ? ' (' . count(DumpState::fromArray($s->dump)->done) . ' tables done)' : ''),
            BackupState::PACK => $this->packProgress($s),
            BackupState::CLOSE, BackupState::SEAL => 'encrypting the last part of the archive',
            BackupState::FINALE => 'finishing the archive',
            BackupState::DONE => 'backup ' . $s->backup . ' is complete',
            default => 'starting',
        };
    }
}
