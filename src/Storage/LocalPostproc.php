<?php

declare(strict_types=1);

namespace ThreadSafe\Storage;

use ThreadSafe\Backup\BackupName;
use ThreadSafe\Backup\Sidecar;

/**
 * The local "transfer": the backup is where it was made. It only confirms that every part is in the storage directory
 * with the size the sidecar file records.
 */
final class LocalPostproc implements Postproc
{
    public function id(): string
    {
        return 'local';
    }

    public function store(BackupName $name, Sidecar $sidecar, LocalStorage $storage): TransferResult
    {
        foreach ($sidecar->parts as $part) {
            $path = $storage->directory() . '/' . $part->file;
            $size = is_file($path) ? @filesize($path) : false;
            if ($size === false || $size !== $part->size) {
                return TransferResult::now($this->id(), false, "The part \"{$part->file}\" is missing or has another size than recorded.");
            }
        }

        return TransferResult::now($this->id(), true, 'Kept in ' . $storage->directory());
    }
}
