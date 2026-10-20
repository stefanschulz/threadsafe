<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

/**
 * What is known about the integrity of a backup (recorded in its sidecar file).
 */
enum BackupStatus: string
{
    /** Made without warnings. */
    case Ok = 'ok';

    /** Made, but something was skipped or reported (vanished files, unreadable files, cells above max_allowed_packet ...). */
    case OkWithWarnings = 'ok-with-warnings';

    /** Found without a record of how it was made, or rebuilt from the archive; not checked yet. */
    case Unverified = 'unverified';

    /** A check found the backup damaged. */
    case Faulty = 'faulty';
}
