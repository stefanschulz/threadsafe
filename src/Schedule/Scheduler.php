<?php

declare(strict_types=1);

namespace ThreadSafe\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use ThreadSafe\Profile\SiteProfile;

/**
 * Decides which scheduled backups are due (docs/PHASE5.md 3.2). It looks at the moment of a call only, takes the **latest scheduled
 * instant not after now** of every profile, and starts it when it has not been dealt with and at most the grace period has passed
 * since; an instant older than that is given up, so that after a day without the trigger exactly one run is made up and never a pile.
 *
 * A run that fails is tried again after a quarter of an hour, twice at most, as long as the grace period holds. The scheduler runs
 * nothing itself and knows nothing about backups: the trigger asks {@see plan()} and reports back with {@see started()},
 * {@see succeeded()}, {@see failed()} and {@see skipped()}. Instants that lie before a schedule was set do not count.
 */
final class Scheduler
{
    /** Minutes between a failed run and its next attempt. */
    public const int RETRY_MINUTES = 15;

    /** The first attempt and two retries. */
    public const int MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly ScheduleStore $store,
        private readonly DateTimeZone $zone,
        private readonly int $graceMinutes = 360,
    ) {
    }

    /**
     * @param list<SiteProfile> $profiles all profiles; those without a schedule are left alone
     */
    public function plan(array $profiles, DateTimeImmutable $now): SchedulePlan
    {
        $local = $now->setTimezone($this->zone);
        $seconds = $now->getTimestamp();
        $due = [];
        $skipped = [];
        foreach ($profiles as $profile) {
            if ($profile->schedule === null || $profile->schedule->paused) {
                continue;
            }
            $state = $this->store->load($profile->id);
            if ($state === null) {
                // never seen (or the state was lost): the schedule counts from now, so that nothing is made up that nobody asked for
                $state = new ScheduleState($seconds);
                $this->store->save($profile->id, $state);
            }
            $instant = $profile->schedule->expression()->previous($local);
            if ($instant === null || $instant->getTimestamp() <= $state->since || $state->handled($instant->getTimestamp())) {
                continue;
            }
            $same = $state->instant === $instant->getTimestamp();
            if ($seconds > $instant->getTimestamp() + $this->graceMinutes * 60) {
                $skipped[] = new SkippedRun($profile->id, $instant, $same ? 'the retries ran out of time' : 'the grace period of ' . $this->graceMinutes . ' minutes had passed');

                continue;
            }
            if ($same && $state->retryAt !== null && $seconds < $state->retryAt) {
                continue;
            }
            $due[] = new DueRun($profile->id, $instant, $same ? $state->attempts + 1 : 1);
        }
        usort($due, static fn (DueRun $a, DueRun $b): int => [$a->instant->getTimestamp(), $a->profileId] <=> [$b->instant->getTimestamp(), $b->profileId]);

        return new SchedulePlan($due, $skipped);
    }

    /** The next scheduled instant of a profile, on the clock of the configured time zone; null without a schedule or while it is paused. */
    public function next(SiteProfile $profile, DateTimeImmutable $now): ?DateTimeImmutable
    {
        return $profile->schedule === null || $profile->schedule->paused ? null : $profile->schedule->expression()->next($now->setTimezone($this->zone));
    }

    /** A run for the scheduled instant has begun; $runId is the backup run that was made for it. */
    public function started(string $profileId, DateTimeImmutable $instant, DateTimeImmutable $now, ?string $runId = null): void
    {
        $state = $this->store->load($profileId);
        $same = $state?->instant === $instant->getTimestamp();
        $this->store->save($profileId, new ScheduleState($state->since ?? $now->getTimestamp(), $instant->getTimestamp(), ScheduleState::RUNNING, ($same ? $state->attempts : 0) + 1, null, $runId));
    }

    /**
     * The scheduled instant a backup run was started for, if the run belongs to the schedule of the profile and has not ended.
     * Null for a run that somebody started by hand.
     */
    public function instantOfRun(string $profileId, string $runId): ?DateTimeImmutable
    {
        $state = $this->store->load($profileId);
        if ($state === null || $state->status !== ScheduleState::RUNNING || $state->runId !== $runId || $state->instant === null) {
            return null;
        }

        return (new DateTimeImmutable('@' . $state->instant))->setTimezone($this->zone);
    }

    /** The backup run that is going for the profile's schedule, if any. */
    public function runOf(string $profileId): ?string
    {
        $state = $this->store->load($profileId);

        return $state !== null && $state->status === ScheduleState::RUNNING ? $state->runId : null;
    }

    /** The run for the scheduled instant ended well (a backup that is complete, with or without warnings). */
    public function succeeded(string $profileId, DateTimeImmutable $instant): void
    {
        $this->finish($profileId, $instant, ScheduleState::DONE);
    }

    /**
     * The run for the scheduled instant failed.
     *
     * @return bool whether it will be tried again (false: the attempts are used up)
     */
    public function failed(string $profileId, DateTimeImmutable $instant, DateTimeImmutable $now): bool
    {
        $state = $this->store->load($profileId);
        $attempts = $state?->instant === $instant->getTimestamp() ? max(1, $state->attempts) : 1;
        $since = $state->since ?? $now->getTimestamp();
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->store->save($profileId, new ScheduleState($since, $instant->getTimestamp(), ScheduleState::GAVE_UP, $attempts));

            return false;
        }
        $this->store->save($profileId, new ScheduleState($since, $instant->getTimestamp(), ScheduleState::PENDING, $attempts, $now->getTimestamp() + self::RETRY_MINUTES * 60));

        return true;
    }

    /** The scheduled instant is given up (see {@see SkippedRun}). */
    public function skipped(string $profileId, DateTimeImmutable $instant): void
    {
        $this->finish($profileId, $instant, ScheduleState::SKIPPED);
    }

    /** The schedule of the profile was set or changed: instants before $now do not count. */
    public function reset(string $profileId, DateTimeImmutable $now): void
    {
        $this->store->save($profileId, new ScheduleState($now->getTimestamp()));
    }

    /** The profile has no schedule any more, or no longer exists. */
    public function forget(string $profileId): void
    {
        $this->store->delete($profileId);
    }

    private function finish(string $profileId, DateTimeImmutable $instant, string $status): void
    {
        $state = $this->store->load($profileId);
        $same = $state?->instant === $instant->getTimestamp();
        $this->store->save($profileId, new ScheduleState($state->since ?? $instant->getTimestamp(), $instant->getTimestamp(), $status, $same ? $state->attempts : 0));
    }
}
