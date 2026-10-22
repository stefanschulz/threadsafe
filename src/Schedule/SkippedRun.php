<?php

declare(strict_types=1);

namespace ThreadSafe\Schedule;

use DateTimeImmutable;

/**
 * A scheduled instant that will not be run any more because too much time has passed (docs/PHASE5.md 3.2). The caller writes the
 * audit entry and then acknowledges it with {@see Scheduler::skipped()}.
 */
final readonly class SkippedRun
{
    public function __construct(public string $profileId, public DateTimeImmutable $instant, public string $reason)
    {
    }
}
