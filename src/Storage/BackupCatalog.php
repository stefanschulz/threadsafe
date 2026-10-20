<?php

declare(strict_types=1);

namespace ThreadSafe\Storage;

use ThreadSafe\Archive\ZipException;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Backup\BackupName;
use ThreadSafe\Backup\BackupStatus;
use ThreadSafe\Backup\Manifest;
use ThreadSafe\Backup\ManifestException;
use ThreadSafe\Backup\PartRecord;
use ThreadSafe\Backup\Sidecar;

/**
 * What a storage directory holds, read from the files themselves: **the file system is the source of truth**. Listing reads
 * only the small sidecar files; a sidecar that is missing or unusable is made again from the manifest in the last archive part
 * (status `unverified`: the sizes and checksums are those of the files as they are now, so only a full verification says
 * whether the archive is intact).
 */
final class BackupCatalog
{
    public function __construct(private readonly LocalStorage $storage)
    {
    }

    /**
     * All backups of a profile (or of every profile that has files here), newest first.
     *
     * @param bool $rebuild make a missing or unusable sidecar file again (a listing then writes)
     *
     * @return list<BackupEntry>
     */
    public function entries(?string $profileId = null, bool $rebuild = true): array
    {
        $entries = [];
        foreach ($this->storage->names($profileId) as $name) {
            $entries[] = $this->entry($name, $rebuild);
        }

        return $entries;
    }

    /**
     * The newest backup, or the one with this name.
     *
     * @throws StorageException there is none
     */
    public function find(string $profileId, ?string $backup): BackupEntry
    {
        if ($backup === null || $backup === 'latest') {
            $first = $this->entries($profileId)[0] ?? null;

            return $first ?? throw new StorageException("There is no backup of profile \"$profileId\" yet.");
        }
        $name = BackupName::parse($backup);
        if ($name === null || $name->profile !== $profileId || !in_array($name->base(), array_map(static fn (BackupName $n): string => $n->base(), $this->storage->names($profileId)), true)) {
            throw new StorageException("There is no backup \"$backup\" of profile \"$profileId\".");
        }

        return $this->entry($name, true);
    }

    public function entry(BackupName $name, bool $rebuild = true): BackupEntry
    {
        $problem = null;
        $rebuilt = false;
        $sidecar = null;
        if (is_file($this->storage->sidecarPath($name))) {
            try {
                $sidecar = $this->storage->readSidecar($name);
            } catch (StorageException $e) {
                $problem = $e->getMessage();
            }
        }

        if ($sidecar === null) {
            $archives = $this->archiveParts($name);
            if ($archives === []) {
                return new BackupEntry($name, null, $this->storage->files($name) === [] ? BackupEntry::UNKNOWN : BackupEntry::UNFINISHED, false, $problem ?? 'There is no finished archive part (a backup is being made, or died).');
            }
            try {
                $sidecar = $this->rebuild($name, write: $rebuild);
                $rebuilt = $rebuild;
            } catch (StorageException $e) {
                return new BackupEntry($name, null, BackupEntry::UNKNOWN, false, $e->getMessage());
            }
        }

        $missing = $this->missingParts($sidecar);

        return new BackupEntry(
            $name,
            $sidecar,
            $missing === [] ? BackupEntry::COMPLETE : BackupEntry::INCOMPLETE,
            $rebuilt,
            $missing === [] ? null : 'Missing or changed parts: ' . implode(', ', $missing),
        );
    }

    /**
     * Makes the sidecar file again from the archive parts that exist.
     *
     * @param bool $write store it (otherwise only return it)
     *
     * @throws StorageException the last part is unreadable or holds no manifest
     */
    public function rebuild(BackupName $name, bool $write = true): Sidecar
    {
        $parts = $this->archiveParts($name);
        if ($parts === []) {
            throw new StorageException("Backup \"{$name->base()}\" has no archive part.");
        }
        $last = $parts[array_key_last($parts)];
        try {
            $manifest = Manifest::fromArchive($last);
        } catch (ManifestException $e) {
            throw new StorageException("Backup \"{$name->base()}\": the last part \"" . basename($last) . '" gives no manifest (' . $e->getMessage() . ') - is a later part missing?', 0, $e);
        }

        $records = [];
        foreach ($parts as $path) {
            try {
                $entries = ZipReader::open($path)->count();
            } catch (ZipException $e) {
                throw new StorageException('Cannot read the archive part "' . basename($path) . '": ' . $e->getMessage(), 0, $e);
            }
            $records[] = new PartRecord(basename($path), (int) filesize($path), (string) hash_file('sha256', $path), $entries);
        }
        $sidecar = new Sidecar($manifest, $records, BackupStatus::Unverified);
        if ($write) {
            $this->storage->writeSidecar($name, $sidecar);
        }

        return $sidecar;
    }

    /**
     * The finished archive parts of a backup, in order (unfinished `.part` and `.cd` files are not parts).
     *
     * @return list<string> absolute paths
     */
    public function archiveParts(BackupName $name): array
    {
        return array_values(array_filter($this->storage->files($name), static fn (string $f): bool => str_ends_with($f, '.zip')));
    }

    /**
     * The parts the sidecar lists that are not in the directory with the recorded size.
     *
     * @return list<string> file names
     */
    private function missingParts(Sidecar $sidecar): array
    {
        $missing = [];
        foreach ($sidecar->parts as $part) {
            $path = $this->storage->directory() . '/' . $part->file;
            $size = is_file($path) ? @filesize($path) : false;
            if ($size === false || $size !== $part->size) {
                $missing[] = $part->file;
            }
        }

        return $missing;
    }
}
