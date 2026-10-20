<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

use ThreadSafe\Archive\Entry;
use ThreadSafe\Archive\ZipException;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Storage\BackupCatalog;
use ThreadSafe\Storage\LocalStorage;
use ThreadSafe\Storage\StorageException;

/**
 * Checks that a backup is intact, at two levels:
 *
 * - **quick:** the sidecar file is readable, every part it lists is there with the recorded size and SHA-256, and no part
 *   file is there that it does not list. Reads every part once (for the checksum), nothing else.
 * - **full:** in addition every part is read as a ZIP archive (structure, every entry's CRC32 and size), carries the right
 *   marker, the last part holds a manifest equal to the one in the sidecar file, the parts are the ones the manifest names,
 *   and the manifest's counts equal what the archive holds (files, directories, tables).
 *
 * The result goes into the sidecar file: a problem makes the status `faulty`, a clean run sets it to `ok` or
 * `ok-with-warnings` (what the backup itself reported). A sidecar file that is missing is made again first; its status is
 * `unverified` until a verification has passed.
 */
final class Verifier
{
    public function __construct(private readonly LocalStorage $storage, private readonly BackupCatalog $catalog)
    {
    }

    public function verify(BackupName $name, bool $full = true): VerificationReport
    {
        $notes = [];
        try {
            $sidecar = $this->storage->readSidecar($name);
        } catch (StorageException $e) {
            try {
                $sidecar = $this->catalog->rebuild($name);
                $notes[] = 'The sidecar file was missing or unusable (' . $e->getMessage() . '); it was made again from the last part. Its sizes and checksums are those of the files as they are now.';
            } catch (StorageException $rebuild) {
                return new VerificationReport($name->base(), $full, 0, 0, [$rebuild->getMessage()], null, $notes);
            }
        }

        $problems = [];
        $bytes = 0;
        $listed = [];
        foreach ($sidecar->parts as $part) {
            $listed[$part->file] = true;
            $path = $this->storage->directory() . '/' . $part->file;
            if (!is_file($path)) {
                $problems[] = "Part \"{$part->file}\" is missing.";
                continue;
            }
            $size = (int) filesize($path);
            if ($size !== $part->size) {
                $problems[] = "Part \"{$part->file}\" has {$size} bytes, the record says {$part->size}.";
                continue;
            }
            $bytes += $size;
            if (hash_file('sha256', $path) !== $part->sha256) {
                $problems[] = "Part \"{$part->file}\" has another SHA-256 than recorded: it was changed or damaged.";
            }
        }
        foreach ($this->catalog->archiveParts($name) as $path) {
            if (!isset($listed[basename($path)])) {
                $problems[] = 'The part "' . basename($path) . '" is in the directory but not in the record.';
            }
        }

        if ($full) {
            array_push($problems, ...$this->full($name, $sidecar));
        }

        $status = $problems !== [] ? BackupStatus::Faulty : ($sidecar->manifest->warnings === [] && $sidecar->manifest->unsupportedObjects === [] ? BackupStatus::Ok : BackupStatus::OkWithWarnings);
        if (!$full && $problems === [] && $sidecar->status !== BackupStatus::Ok && $sidecar->status !== BackupStatus::OkWithWarnings) {
            // a quick check does not clear a backup that was never checked in full (or was found faulty)
            $status = $sidecar->status;
        }
        if ($status !== $sidecar->status) {
            try {
                $this->storage->writeSidecar($name, $sidecar->withStatus($status));
            } catch (StorageException $e) {
                $notes[] = 'The result could not be stored in the sidecar file: ' . $e->getMessage();
            }
        }

        return new VerificationReport($name->base(), $full, count($sidecar->parts), $bytes, $problems, $status, $notes);
    }

    /**
     * @return list<string> problems
     */
    private function full(BackupName $name, Sidecar $sidecar): array
    {
        $problems = [];
        $files = 0;
        $directories = 0;
        $tables = 0;
        $last = count($sidecar->parts) - 1;

        foreach ($sidecar->parts as $index => $part) {
            $path = $this->storage->directory() . '/' . $part->file;
            if (!is_file($path)) {
                continue;   // already a problem of the quick level
            }
            try {
                $reader = ZipReader::open($path);
                $result = $reader->verify();
            } catch (ZipException $e) {
                $problems[] = "Part \"{$part->file}\" is not a sound archive: " . $e->getMessage();
                continue;
            }
            foreach ($result->problems as $problem) {
                $problems[] = "Part \"{$part->file}\": $problem";
            }
            if ($reader->count() !== $part->entries) {
                $problems[] = "Part \"{$part->file}\" holds {$reader->count()} entries, the record says {$part->entries}.";
            }

            try {
                $marker = $reader->find(PartMarker::ENTRY);
                if (!$marker instanceof Entry) {
                    $problems[] = "Part \"{$part->file}\" has no part marker.";
                } else {
                    $mark = PartMarker::fromJson($reader->contents($marker, 65536));
                    if ($mark->backupId !== $name->base() || $mark->index !== $index + 1) {
                        $problems[] = "Part \"{$part->file}\" says it is part {$mark->index} of \"{$mark->backupId}\"; it should be part " . ($index + 1) . " of \"{$name->base()}\".";
                    }
                }

                if ($index === $last) {
                    $problems = [...$problems, ...$this->checkManifest($reader, $sidecar)];
                } elseif ($reader->find(Manifest::ENTRY) instanceof Entry) {
                    $problems[] = "Part \"{$part->file}\" holds a manifest but is not the last part.";
                }

                foreach ($reader->entries() as $entry) {
                    if (str_starts_with($entry->name, 'files/')) {
                        $entry->isDirectory ? ++$directories : ++$files;
                    } elseif (str_starts_with($entry->name, 'db/') && !str_starts_with($entry->name, 'db/_') && str_ends_with($entry->name, '.sql')) {
                        ++$tables;
                    }
                }
            } catch (ZipException | ManifestException $e) {
                $problems[] = "Part \"{$part->file}\": " . $e->getMessage();
            }
        }

        $counts = $sidecar->manifest->counts;
        foreach (['files' => $files, 'directories' => $directories, 'tables' => $tables] as $what => $found) {
            if ($found !== $counts[$what]) {
                $problems[] = "The manifest says {$counts[$what]} $what, the archive holds $found.";
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function checkManifest(ZipReader $reader, Sidecar $sidecar): array
    {
        $entry = $reader->find(Manifest::ENTRY);
        if (!$entry instanceof Entry) {
            return ['The last part holds no manifest.'];
        }
        $problems = [];
        $manifest = Manifest::fromJson($reader->contents($entry, 4 * 1048576));
        if ($manifest->toArray() !== $sidecar->manifest->toArray()) {
            $problems[] = 'The manifest in the archive differs from the one in the sidecar file.';
        }
        $named = array_map(static fn (array $p): string => $p['file'], $manifest->parts);
        $recorded = array_map(static fn (PartRecord $p): string => $p->file, $sidecar->parts);
        if ($named !== $recorded) {
            $problems[] = 'The parts the manifest names (' . implode(', ', $named) . ') are not the parts of the record (' . implode(', ', $recorded) . ').';
        }

        return $problems;
    }
}
