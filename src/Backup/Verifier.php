<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

use ThreadSafe\Engine\Budget;
use ThreadSafe\Storage\LocalStorage;

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
 *
 * The work is done by {@see VerifyRun}, the same task the web interface runs in ticks; here it runs in one go (no time limit).
 */
final class Verifier
{
    public function __construct(private readonly LocalStorage $storage)
    {
    }

    public function verify(BackupName $name, bool $full = true): VerificationReport
    {
        $task = new VerifyRun(fn (): LocalStorage => $this->storage);
        $state = $task->initialState(['profile' => $name->profile, 'backup' => $name->base(), 'full' => $full]);
        do {
            $outcome = $task->tick($state, Budget::unlimited());
            $state = $outcome->state;
        } while (!$outcome->finished);

        $result = $state['result'] ?? null;

        return is_array($result) ? VerificationReport::fromArray($result) : new VerificationReport($name->base(), $full, 0, 0, ['The verification ended without a result.'], null);
    }
}
