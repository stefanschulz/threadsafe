<?php

declare(strict_types=1);

namespace ThreadSafe\Update;

use ThreadSafe\Engine\Lock;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;

/**
 * Whether ThreadSafe is in the middle of something that an update must not cut through (docs/PHASE7.md 3.5): a backup, a check, the
 * trigger, a restore. An update waits until nothing runs; it never interrupts.
 */
final class Busy
{
    /** A run of the web interface that did its last tick this recently is still being worked on, whether or not a process holds its lock. */
    private const int RECENT = 120;

    /**
     * @param (\Closure(): int)|null $clock for tests: the time in seconds
     *
     * @return string|null what is running, as a sentence, or null if nothing is
     */
    public static function reason(string $data, ?\Closure $clock = null): ?string
    {
        $data = rtrim($data, '/\\');
        foreach (glob($data . '/locks/*.lock') ?: [] as $lock) {
            // the lock of an update or a roll-back is the update's own business and has its own message
            if (basename($lock) !== 'update.lock' && Lock::isHeld($lock)) {
                return 'Something is running (' . (Lock::holder($lock) ?? basename($lock)) . '). Wait until it has finished.';
            }
        }
        $now = $clock !== null ? $clock() : time();
        foreach ((new RunStore($data . '/runs'))->all() as $run) {
            if ($run->task === 'update' || $run->status !== RunStatus::Running) {
                continue;
            }
            if (Lock::isHeld((new RunStore($data . '/runs'))->lockPath($run->id)) || $now - $run->updated < self::RECENT) {
                return 'A ' . $run->task . ' is running (' . $run->id . '). Wait until it has finished, or give it up.';
            }
        }

        return null;
    }

    private function __construct()
    {
    }
}
