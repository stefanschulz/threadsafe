<?php

declare(strict_types=1);

namespace ThreadSafe\Schedule;

/**
 * What the scheduler found at one moment: the runs to start, in the order of the queue (the oldest scheduled instant first, then
 * the profile id), and the scheduled instants that are given up.
 */
final readonly class SchedulePlan
{
    /**
     * @param list<DueRun>     $due
     * @param list<SkippedRun> $skipped
     */
    public function __construct(public array $due = [], public array $skipped = [])
    {
    }
}
