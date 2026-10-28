<?php

declare(strict_types=1);

namespace ThreadSafe\App;

use DateTimeImmutable;
use ThreadSafe\Profile\ScheduleSettings;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Schedule\ScheduleState;

/**
 * One line of the table of schedules (docs/PHASE8.md 3.3): a profile, its website type as people know it, its schedule, when it runs next
 * (not while it is paused), what became of the last run that the schedule asked for, and the profiles whose next run is so close that they
 * run one after the other.
 */
final readonly class ScheduleRow
{
    /**
     * @param list<string> $overlaps names of the profiles that start within {@see ScheduleService::OVERLAP_MINUTES} of this one
     */
    public function __construct(
        public SiteProfile $profile,
        public string $typeTitle,
        public ?ScheduleSettings $schedule,
        public ?DateTimeImmutable $next,
        public ?ScheduleState $last,
        public array $overlaps = [],
    ) {
    }

    public function paused(): bool
    {
        return $this->schedule !== null && $this->schedule->paused;
    }
}
