<?php

declare(strict_types=1);

namespace ThreadSafe\Restore;

use ThreadSafe\Archive\Entry;
use ThreadSafe\Archive\ZipException;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Backup\BackupName;
use ThreadSafe\Backup\Manifest;
use ThreadSafe\Backup\ManifestException;
use ThreadSafe\Backup\PartMarker;
use ThreadSafe\Core\ExitCode;

/**
 * A backup as it lies on disk, found from any one of its archive parts: the parts in order and the manifest from the last one.
 * Needs nothing of ThreadSafe's installation (no profile, no sidecar file), so a restore tool on a bare host can use it.
 */
final readonly class BackupSource
{
    /**
     * @param list<string> $parts absolute paths, in the order of the backup
     */
    private function __construct(public array $parts, public Manifest $manifest)
    {
    }

    /**
     * @param string $path any part of the backup (`<profile>_<time>_<random>.zip` or `...-pNN.zip`)
     *
     * @throws RestoreException the file is no part of a backup, a part is missing, or a part does not belong to the backup
     */
    public static function open(string $path): self
    {
        $real = is_file($path) ? realpath($path) : false;
        if ($real === false) {
            throw new RestoreException("The archive \"$path\" does not exist.", ExitCode::GuardFailed);
        }
        $directory = dirname($real);
        $info = BackupName::fromFile(basename($real));
        if ($info === null || $info['extension'] !== 'zip') {
            throw new RestoreException('"' . basename($real) . '" is not a part of a ThreadSafe backup (the name should look like <profile>_<date>-<time>_<code>.zip or ...-p01.zip).', ExitCode::GuardFailed);
        }
        $base = $info['name']->base();

        // the parts of this backup that are in the directory; the last one has the manifest
        $found = [];
        foreach (scandir($directory) ?: [] as $file) {
            $other = BackupName::fromFile($file);
            if ($other !== null && $other['extension'] === 'zip' && $other['name']->base() === $base) {
                $found[$other['part'] ?? 0] = $directory . '/' . $file;
            }
        }
        ksort($found);
        $last = end($found);
        if ($last === false) {
            throw new RestoreException("No part of the backup \"$base\" found in \"$directory\".", ExitCode::GuardFailed);
        }

        try {
            $manifest = Manifest::fromArchive($last);
        } catch (ManifestException $e) {
            throw new RestoreException('The last part of the backup (' . basename($last) . ') cannot be used: ' . $e->getMessage() . ' Is a later part missing?', ExitCode::GuardFailed, $e);
        }
        if ($manifest->backupId !== $base) {
            throw new RestoreException('The manifest in ' . basename($last) . " belongs to the backup \"{$manifest->backupId}\", not to \"$base\".", ExitCode::GuardFailed);
        }

        $parts = [];
        $missing = [];
        foreach ($manifest->parts as $part) {
            $file = $directory . '/' . $part['file'];
            is_file($file) ? $parts[] = $file : $missing[] = $part['file'];
        }
        if ($missing !== []) {
            throw new RestoreException('The backup consists of ' . count($manifest->parts) . ' part(s); missing in "' . $directory . '": ' . implode(', ', $missing) . '.', ExitCode::GuardFailed);
        }

        foreach ($parts as $index => $file) {
            self::checkMarker($file, $base, $index + 1);
        }

        return new self($parts, $manifest);
    }

    /**
     * @throws RestoreException
     */
    private static function checkMarker(string $file, string $base, int $index): void
    {
        try {
            $reader = ZipReader::open($file);
            $entry = $reader->find(PartMarker::ENTRY);
            if (!$entry instanceof Entry) {
                throw new RestoreException(basename($file) . ' has no part marker: it is not a part written by ThreadSafe.', ExitCode::GuardFailed);
            }
            $marker = PartMarker::fromJson($reader->contents($entry, 65536));
        } catch (ZipException | ManifestException $e) {
            throw new RestoreException(basename($file) . ' cannot be read: ' . $e->getMessage(), ExitCode::GuardFailed, $e);
        }
        if ($marker->backupId !== $base || $marker->index !== $index) {
            throw new RestoreException(basename($file) . " says it is part {$marker->index} of \"{$marker->backupId}\"; it should be part $index of \"$base\".", ExitCode::GuardFailed);
        }
    }
}
