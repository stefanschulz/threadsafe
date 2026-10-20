<?php

declare(strict_types=1);

namespace ThreadSafe\Storage;

use ThreadSafe\Backup\BackupName;
use ThreadSafe\Backup\Sidecar;

/**
 * What happens to a backup after it is finished and verified in the local storage. Only {@see LocalPostproc} exists; SFTP,
 * S3 and WebDAV come later as further implementations, and each of them adds a record to the `transfers` of the sidecar file.
 */
interface Postproc
{
    /** Short identifier of the target, e.g. "local" or "sftp:backup-host". */
    public function id(): string;

    /**
     * Takes care of a finished backup. Must not throw for a failed transfer: the failure is the result.
     */
    public function store(BackupName $name, Sidecar $sidecar, LocalStorage $storage): TransferResult;
}
