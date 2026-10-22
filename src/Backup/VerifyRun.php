<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

use Closure;
use InvalidArgumentException;
use ThreadSafe\Archive\Entry;
use ThreadSafe\Archive\ZipException;
use ThreadSafe\Archive\ZipFormat;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Crypto\AgeDecryptStream;
use ThreadSafe\Crypto\Identity;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\Task;
use ThreadSafe\Engine\TickOutcome;
use ThreadSafe\Storage\BackupCatalog;
use ThreadSafe\Storage\LocalStorage;
use ThreadSafe\Storage\StorageException;

/**
 * Checks that a backup is intact, in ticks (docs/PHASE4.md 3.7): reading every byte of a multi-gigabyte archive does not fit into
 * one web request. {@see Verifier} describes the two levels (quick: size and SHA-256 of every part; full: also every part read as a
 * ZIP archive with the CRC32 of every entry, the part markers, the manifest and the counts); this task does exactly that, saving its
 * place after every tick: the running SHA-256 of the part in work (a serialised hash context), and the position in the central
 * directory of the archive being read.
 *
 * Differences from reading it in one go: an entry is read as a whole inside a tick (as the extractor does with a deflated one), and
 * the check that no entry overlaps another needs the entries in the order of the file, which every archive of ThreadSafe has; with
 * another order that check is skipped for the part.
 */
final class VerifyRun implements Task
{
    /** @var Closure(string): LocalStorage */
    private readonly Closure $storageFor;

    /**
     * @param Closure(string): LocalStorage $storageFor  the storage of a profile, by the id of the profile
     * @param list<Identity>|null           $identities the keys or passphrases that open an encrypted backup. Without them an encrypted backup gets the
     *                                                  quick check only (the sealed files are compared with the record); they are never part of the state of the run.
     */
    public function __construct(Closure $storageFor, private readonly ?array $identities = null)
    {
        $this->storageFor = $storageFor;
    }

    public function id(): string
    {
        return 'verify';
    }

    /**
     * @param array<string, mixed> $parameters `profile` (id), `backup` (base name), `full` (default true)
     */
    public function initialState(array $parameters): array
    {
        $profile = $parameters['profile'] ?? null;
        $backup = $parameters['backup'] ?? null;
        if (!is_string($profile) || !is_string($backup) || BackupName::parse($backup) === null) {
            throw new InvalidArgumentException('A verification needs the id of a profile and the name of a backup.');
        }

        return (new VerifyState($profile, $backup, ($parameters['full'] ?? true) === true))->toArray();
    }

    public function tick(array $state, Budget $budget): TickOutcome
    {
        $s = VerifyState::fromArray($state);
        $name = BackupName::parse($s->backup) ?? throw new InvalidArgumentException('The backup name is invalid.');
        $storage = ($this->storageFor)($s->profileId);

        if ($s->stage === VerifyState::START) {
            $sidecar = $this->start($s, $name, $storage);
            if ($sidecar === null) {
                return $this->end($s, $name, $storage, null);
            }
        } else {
            $sidecar = $storage->readSidecar($name);
        }

        if ($s->stage === VerifyState::HASH && !$this->hash($s, $sidecar, $storage, $name, $budget)) {
            return new TickOutcome($s->toArray(), false, $this->progress($s, $sidecar));
        }
        if ($s->stage === VerifyState::ZIP && !$this->zip($s, $sidecar, $storage, $name, $budget)) {
            return new TickOutcome($s->toArray(), false, $this->progress($s, $sidecar));
        }

        return $this->end($s, $name, $storage, $sidecar);
    }

    /**
     * How far a run is, for a progress bar.
     *
     * @param array<string, mixed> $state
     */
    public static function percent(array $state): ?int
    {
        try {
            $s = VerifyState::fromArray($state);
        } catch (InvalidArgumentException) {
            return null;
        }
        if ($s->stage === VerifyState::DONE) {
            return 100;
        }

        return $s->total > 0 ? min(99, (int) floor(100 * $s->done / $s->total)) : null;
    }

    // ------------------------------------------------------------------------------------------------ start

    private function start(VerifyState $s, BackupName $name, LocalStorage $storage): ?Sidecar
    {
        try {
            $sidecar = $storage->readSidecar($name);
        } catch (StorageException $e) {
            try {
                $sidecar = (new BackupCatalog($storage))->rebuild($name);
                $s->notes[] = 'The sidecar file was missing or unusable (' . $e->getMessage() . '); it was made again from the last part. Its sizes and checksums are those of the files as they are now.';
            } catch (StorageException $rebuild) {
                $s->problem($rebuild->getMessage());

                return null;
            }
        }

        if ($s->full && $sidecar->encryption !== null && $this->identities === null) {
            $s->full = false;
            $s->notes[] = 'This backup is encrypted: a full check reads it through its key or passphrase, which are not given here, so only the sizes and SHA-256 sums of the parts were checked (verify <profile> --identity-file=FILE, or --passphrase-file=FILE for a backup made for a passphrase, does the full check).';
        }
        $s->partCount = count($sidecar->parts);
        $s->total = 0;
        foreach ($sidecar->parts as $part) {
            $s->total += $part->size * ($s->full ? 2 : 1);
        }
        $s->stage = VerifyState::HASH;
        $s->part = 0;

        return $sidecar;
    }

    // ------------------------------------------------------------------------------------------------ the checksums

    /**
     * @return bool all parts are checked (false: the budget ran out)
     */
    private function hash(VerifyState $s, Sidecar $sidecar, LocalStorage $storage, BackupName $name, Budget $budget): bool
    {
        while ($s->part < count($sidecar->parts)) {
            $part = $sidecar->parts[$s->part];
            $path = $storage->directory() . '/' . $part->file;

            if ($s->hash === null) {
                if (!is_file($path)) {
                    $s->problem("Part \"{$part->file}\" is missing.");
                    $s->done += $part->size;
                    ++$s->part;
                    continue;
                }
                $size = (int) filesize($path);
                if ($size !== $part->size) {
                    $s->problem("Part \"{$part->file}\" has {$size} bytes, the record says {$part->size}.");
                    $s->done += $part->size;
                    ++$s->part;
                    continue;
                }
                $s->bytes += $size;
                $s->hash = ['offset' => 0, 'context' => base64_encode(serialize(hash_init('sha256')))];
            }

            $context = unserialize(base64_decode($s->hash['context']), ['allowed_classes' => [\HashContext::class]]);
            $handle = @fopen($path, 'rb');
            if (!$context instanceof \HashContext || $handle === false) {
                $s->problem("Part \"{$part->file}\" cannot be read.");
                $s->hash = null;
                ++$s->part;
                continue;
            }
            fseek($handle, $s->hash['offset']);
            $offset = $s->hash['offset'];
            $finished = false;
            while (true) {
                $block = fread($handle, 1048576);
                if ($block === false || $block === '') {
                    $finished = true;
                    break;
                }
                hash_update($context, $block);
                $offset += strlen($block);
                $s->done += strlen($block);
                if ($budget->exhausted()) {
                    $finished = $offset >= $part->size;
                    break;
                }
            }
            fclose($handle);

            if (!$finished) {
                $s->hash = ['offset' => $offset, 'context' => base64_encode(serialize($context))];

                return false;
            }
            if (hash_final($context) !== $part->sha256) {
                $s->problem("Part \"{$part->file}\" has another SHA-256 than recorded: it was changed or damaged.");
            }
            $s->hash = null;
            ++$s->part;
            if ($budget->exhausted() && $s->part < count($sidecar->parts)) {
                return false;
            }
        }

        foreach ((new BackupCatalog($storage))->archiveParts($name) as $path) {
            $listed = false;
            foreach ($sidecar->parts as $part) {
                $listed = $listed || $part->file === basename($path);
            }
            if (!$listed) {
                $s->problem('The part "' . basename($path) . '" is in the directory but not in the record.');
            }
        }
        $s->stage = $s->full ? VerifyState::ZIP : VerifyState::DONE;
        $s->part = 0;

        return $s->stage === VerifyState::DONE || !$budget->exhausted();
    }

    // ------------------------------------------------------------------------------------------------ the archives

    /**
     * @return bool all parts are read (false: the budget ran out)
     */
    private function zip(VerifyState $s, Sidecar $sidecar, LocalStorage $storage, BackupName $name, Budget $budget): bool
    {
        $last = count($sidecar->parts) - 1;
        while ($s->part < count($sidecar->parts)) {
            $part = $sidecar->parts[$s->part];
            $path = $storage->directory() . '/' . $part->file;
            if (!is_file($path)) {
                ++$s->part;
                $s->zip = null;
                continue;   // already a problem of the first pass, or the size was wrong
            }
            $sealed = str_ends_with($part->file, '.age') && $this->identities !== null;
            $source = $sealed ? AgeDecryptStream::url($path, $this->identities ?? []) : $path;
            try {
                $reader = ZipReader::open($source);
            } catch (ZipException $e) {
                $s->problem("Part \"{$part->file}\" is not a sound archive: " . ($sealed ? (AgeDecryptStream::lastError() ?? $e->getMessage()) : $e->getMessage()));
                $s->done += $part->size;
                ++$s->part;
                $s->zip = null;
                if ($sealed) {
                    AgeDecryptStream::release($source);
                }

                continue;
            }

            if ($s->zip === null) {
                $this->checkPart($s, $reader, $part, $s->part, $last, $name, $sidecar);
                $s->zip = ['from' => 0, 'position' => 0, 'files' => 0, 'directories' => 0, 'tables' => 0, 'lastOffset' => -1, 'lastEnd' => 0, 'ordered' => true];
            }
            $zip = $s->zip;
            $count = $reader->count();

            try {
                foreach ($reader->entries($zip['from'], $zip['position']) as $index => $entry) {
                    $this->readEntry($s, $reader, $entry, $part, $zip);
                    $zip['from'] = $index + 1;
                    $zip['position'] = $entry->nextRecord;
                    $s->done = $this->zipDone($sidecar, $s->part, $zip['from'] / max(1, $count));
                    if ($budget->exhausted() && $zip['from'] < $count) {
                        $s->zip = $zip;

                        return false;
                    }
                }
            } catch (ZipException $e) {
                $s->problem("Part \"{$part->file}\": " . $e->getMessage());
            } finally {
                if ($sealed) {
                    AgeDecryptStream::release($source);
                }
            }

            $s->files += $zip['files'];
            $s->directories += $zip['directories'];
            $s->tables += $zip['tables'];
            $s->done = $this->zipDone($sidecar, $s->part + 1, 0.0);
            $s->zip = null;
            ++$s->part;
            if ($budget->exhausted() && $s->part < count($sidecar->parts)) {
                return false;
            }
        }
        $s->stage = VerifyState::DONE;

        return true;
    }

    /** Bytes of both passes that are done when all parts have their checksum checked, the parts before $index are read, and $fraction of part $index. */
    private function zipDone(Sidecar $sidecar, int $index, float $fraction): int
    {
        $done = 0;
        foreach ($sidecar->parts as $i => $part) {
            $done += $part->size + ($i < $index ? $part->size : ($i === $index ? (int) floor($part->size * $fraction) : 0));
        }

        return $done;
    }

    /**
     * What is checked once per part: the number of entries, the marker, the manifest.
     */
    private function checkPart(VerifyState $s, ZipReader $reader, PartRecord $part, int $index, int $last, BackupName $name, Sidecar $sidecar): void
    {
        if ($reader->count() !== $part->entries) {
            $s->problem("Part \"{$part->file}\" holds {$reader->count()} entries, the record says {$part->entries}.");
        }
        try {
            $marker = $reader->find(PartMarker::ENTRY);
            if (!$marker instanceof Entry) {
                $s->problem("Part \"{$part->file}\" has no part marker.");
            } else {
                $mark = PartMarker::fromJson($reader->contents($marker, 65536));
                if ($mark->backupId !== $name->base() || $mark->index !== $index + 1) {
                    $s->problem("Part \"{$part->file}\" says it is part {$mark->index} of \"{$mark->backupId}\"; it should be part " . ($index + 1) . " of \"{$name->base()}\".");
                }
            }

            if ($index === $last) {
                foreach ($this->checkManifest($reader, $sidecar) as $problem) {
                    $s->problem($problem);
                }
            } elseif ($reader->find(Manifest::ENTRY) instanceof Entry) {
                $s->problem("Part \"{$part->file}\" holds a manifest but is not the last part.");
            }
        } catch (ZipException | ManifestException $e) {
            $s->problem("Part \"{$part->file}\": " . $e->getMessage());
        }
    }

    /**
     * One entry: its name, its data against its CRC32 and size, its place in the file, and the counts the manifest is checked against.
     *
     * @param array{from: int, position: int, files: int, directories: int, tables: int, lastOffset: int, lastEnd: int, ordered: bool} $zip
     */
    private function readEntry(VerifyState $s, ZipReader $reader, Entry $entry, PartRecord $part, array &$zip): void
    {
        if (str_starts_with($entry->name, 'files/')) {
            $entry->isDirectory ? ++$zip['directories'] : ++$zip['files'];
        } elseif (str_starts_with($entry->name, 'db/') && !str_starts_with($entry->name, 'db/_') && str_ends_with($entry->name, '.sql')) {
            ++$zip['tables'];
        }

        $reason = ZipFormat::unsafeReason($entry->name);
        if ($reason !== null) {
            $s->problem("Part \"{$part->file}\": \"{$entry->name}\": $reason");

            return;
        }
        try {
            $reader->extract($entry, static function (string $chunk): void {
            });
            if (!$entry->isDirectory) {
                if ($zip['ordered'] && $entry->localOffset > $zip['lastOffset']) {
                    if ($entry->localOffset < $zip['lastEnd']) {
                        $s->problem("Part \"{$part->file}\": \"{$entry->name}\" overlaps the entry before it in the file.");
                    }
                    $zip['lastOffset'] = $entry->localOffset;
                    $zip['lastEnd'] = $reader->dataOffset($entry) + $entry->compressedSize;
                } else {
                    $zip['ordered'] = false;   // not in the order of the file: the overlap check would need all entries at once
                }
            }
        } catch (ZipException $e) {
            $s->problem("Part \"{$part->file}\": " . $e->getMessage());
        }
    }

    /**
     * @return list<string> problems
     */
    private function checkManifest(ZipReader $reader, Sidecar $sidecar): array
    {
        $entry = $reader->find(Manifest::ENTRY);
        if (!$entry instanceof Entry) {
            return ['The last part holds no manifest.'];
        }
        $problems = [];
        $manifest = Manifest::fromJson($reader->contents($entry, 4 * 1048576));
        // (the sidecar file of an encrypted backup holds the minimal manifest, made from the one in the archive)
        if (($sidecar->encryption !== null ? $manifest->minimal()->toArray() : $manifest->toArray()) !== $sidecar->manifest->toArray()) {
            $problems[] = 'The manifest in the archive differs from the one in the sidecar file.';
        }
        $named = array_map(static fn (array $p): string => $p['file'], $manifest->parts);
        $recorded = array_map(static fn (PartRecord $p): string => $p->file, $sidecar->parts);
        if ($named !== $recorded) {
            $problems[] = 'The parts the manifest names (' . implode(', ', $named) . ') are not the parts of the record (' . implode(', ', $recorded) . ').';
        }

        return $problems;
    }

    // ------------------------------------------------------------------------------------------------ end

    private function end(VerifyState $s, BackupName $name, LocalStorage $storage, ?Sidecar $sidecar): TickOutcome
    {
        $status = null;
        if ($sidecar !== null) {
            if ($s->full) {
                $counts = $sidecar->manifest->counts;
                foreach (['files' => $s->files, 'directories' => $s->directories, 'tables' => $s->tables] as $what => $found) {
                    if ($found !== $counts[$what]) {
                        $s->problem("The manifest says {$counts[$what]} $what, the archive holds $found.");
                    }
                }
            }
            $faulty = $s->problems !== [] || $s->problemsDropped > 0;
            $status = $faulty ? BackupStatus::Faulty : ($sidecar->manifest->warnings === [] && $sidecar->manifest->unsupportedObjects === [] ? BackupStatus::Ok : BackupStatus::OkWithWarnings);
            if (!$s->full && !$faulty && $sidecar->status !== BackupStatus::Ok && $sidecar->status !== BackupStatus::OkWithWarnings) {
                // a quick check does not clear a backup that was never checked in full (or was found faulty)
                $status = $sidecar->status;
            }
            if ($status !== $sidecar->status) {
                try {
                    $storage->writeSidecar($name, $sidecar->withStatus($status));
                } catch (StorageException $e) {
                    $s->notes[] = 'The result could not be stored in the sidecar file: ' . $e->getMessage();
                }
            }
        }

        $problems = $s->problems;
        if ($s->problemsDropped > 0) {
            $problems[] = "... and {$s->problemsDropped} more problems.";
        }
        $report = new VerificationReport($s->backup, $s->full, $s->partCount, $s->bytes, $problems, $status, $s->notes);
        $s->stage = VerifyState::DONE;
        $s->status = $status->value ?? '';
        $s->result = $report->toArray();

        return new TickOutcome($s->toArray(), true, $problems === [] ? 'backup ' . $s->backup . ' is intact' : 'backup ' . $s->backup . ' is NOT intact');
    }

    private function progress(VerifyState $s, Sidecar $sidecar): string
    {
        $parts = count($sidecar->parts);
        $part = min($s->part + 1, max(1, $parts));
        if ($s->stage === VerifyState::HASH) {
            return sprintf('checking the checksum of part %d of %d', $part, $parts);
        }

        return sprintf('reading part %d of %d (entry %d of the archive)', $part, $parts, $s->zip['from'] ?? 0);
    }
}
