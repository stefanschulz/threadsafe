<?php

declare(strict_types=1);

namespace ThreadSafe\Update;

/**
 * One step from one format of the data directory to the next (docs/PHASE7.md 3.6). It must be **idempotent** (it can run again after
 * a cut-off) and must leave files that the release before it can still read, so that a roll-back is the restore of a copy and not a repair.
 */
interface Migration
{
    /** The format of the data directory after this migration. */
    public function to(): int;

    /** A sentence for the audit log and the page, e.g. "Schedules get a time zone". */
    public function describe(): string;

    /**
     * @throws UpdateException the data cannot be migrated
     */
    public function run(string $data): void;
}
