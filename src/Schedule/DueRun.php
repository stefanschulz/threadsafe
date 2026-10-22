<?php

declare(strict_types=1);

namespace ThreadSafe\Schedule;

use DateTimeImmutable;

/**
 * A scheduled backup that should be started now: which profile, for which scheduled instant, and which attempt it will be (1 =
 * the first, 2 and 3 = the retries of a run that failed).
 */
final readonly class DueRun
{
    public function __construct(public string $profileId, public DateTimeImmutable $instant, public int $attempt = 1)
    {
    }
}
