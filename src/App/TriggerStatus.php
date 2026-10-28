<?php

declare(strict_types=1);

namespace ThreadSafe\App;

use Closure;
use DateTimeImmutable;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Schedule\Heartbeat;
use ThreadSafe\Schedule\Scheduler;

/**
 * What the dashboard and the System page say about the trigger (docs/PHASE5.md 3.3): when it called last, whether that is too long
 * ago for the rhythm it is meant to have, and when each scheduled profile is due next. Cheap: the heartbeat file and the cron
 * arithmetic, no listing of any storage.
 */
final class TriggerStatus
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param int                 $expectedIntervalMinutes how often the trigger is meant to come (`schedule.expectedIntervalMinutes`)
     * @param Closure(): int|null $clock                   the time in seconds (for tests)
     */
    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly Heartbeat $heartbeat,
        private readonly int $expectedIntervalMinutes,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /**
     * @param list<SiteProfile> $profiles
     *
     * @return array{scheduled: int, last: array{time: int, via: string, note: string, age: int}|null, warning: string|null, next: array<string, DateTimeImmutable>}
     */
    public function build(array $profiles): array
    {
        $now = ($this->clock)();
        $next = [];
        foreach ($profiles as $profile) {
            // a paused schedule is not kept by the trigger, so it neither has a next run nor makes the trigger's absence a problem
            $when = $profile->schedule === null ? null : $this->scheduler->next($profile, new DateTimeImmutable('@' . $now));
            if ($when !== null) {
                $next[$profile->id] = $when;
            }
        }
        $beat = $this->heartbeat->read();
        $last = $beat === null ? null : $beat + ['age' => max(0, $now - $beat['time'])];

        $warning = null;
        if ($next !== []) {
            $limit = max(2 * $this->expectedIntervalMinutes, 30) * 60;
            if ($last === null) {
                $warning = 'No trigger has called yet: scheduled backups are made only when a cron job calls the trigger (the System page says how).';
            } elseif ($last['age'] > $limit) {
                $warning = 'The trigger last called ' . self::age($last['age']) . ' ago, but it is meant to come every ' . self::minutes($this->expectedIntervalMinutes) . ': scheduled backups are not made while it stays away.';
            }
        }

        return ['scheduled' => count($next), 'last' => $last, 'warning' => $warning, 'next' => $next];
    }

    private static function age(int $seconds): string
    {
        return match (true) {
            $seconds < 120 => $seconds . ' seconds',
            $seconds < 7200 => intdiv($seconds, 60) . ' minutes',
            $seconds < 172800 => intdiv($seconds, 3600) . ' hours',
            default => intdiv($seconds, 86400) . ' days',
        };
    }

    private static function minutes(int $minutes): string
    {
        return $minutes >= 1440 && $minutes % 1440 === 0 ? ($minutes === 1440 ? 'day' : intdiv($minutes, 1440) . ' days') : ($minutes >= 120 && $minutes % 60 === 0 ? intdiv($minutes, 60) . ' hours' : $minutes . ' minutes');
    }
}
