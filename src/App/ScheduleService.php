<?php

declare(strict_types=1);

namespace ThreadSafe\App;

use DateTimeImmutable;
use ThreadSafe\Plugin\PluginError;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\ScheduleSettings;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Schedule\Scheduler;
use ThreadSafe\Schedule\ScheduleStore;

/**
 * The schedules of all profiles together (docs/PHASE8.md 3.3, 3.4): the lines of the table with their order, and the changes of one or
 * several schedules at once. It returns plain data and prints nothing; the page and, later, other front ends format the results.
 */
final class ScheduleService
{
    /** The columns the table can be sorted by. */
    public const array SORTS = ['name', 'type', 'time'];

    /** Next runs closer than this many minutes to each other are said to run one after the other. */
    public const int OVERLAP_MINUTES = 10;

    public function __construct(
        private readonly ProfileService $profiles,
        private readonly Scheduler $scheduler,
        private readonly ScheduleStore $states,
        private readonly PluginRegistry $plugins,
    ) {
    }

    /**
     * The lines of the table, for the profiles that can be read, in the order asked for.
     *
     * Sorted by `time`, the profiles that will run come first, earliest first (latest first when descending); then the paused ones and then those
     * without a schedule, each by name, whichever way the sort goes. Equal values are ordered by name.
     *
     * @param string $sort      one of {@see SORTS}
     * @param string $direction `asc` or `desc`
     *
     * @return list<ScheduleRow>
     */
    public function rows(DateTimeImmutable $now, string $sort = 'time', string $direction = 'asc'): array
    {
        $profiles = $this->profiles->listing()['profiles'];
        $rows = [];
        foreach ($profiles as $profile) {
            $rows[] = new ScheduleRow($profile, $this->typeTitle($profile->type), $profile->schedule, $this->scheduler->next($profile, $now), $this->states->load($profile->id));
        }
        $rows = $this->withOverlaps($rows);

        $descending = $direction === 'desc';
        $byName = static fn (ScheduleRow $a, ScheduleRow $b): int => strnatcasecmp($a->profile->name, $b->profile->name) ?: strcmp($a->profile->id, $b->profile->id);
        usort($rows, match ($sort) {
            'name' => static fn (ScheduleRow $a, ScheduleRow $b): int => $descending ? $byName($b, $a) : $byName($a, $b),
            'type' => static fn (ScheduleRow $a, ScheduleRow $b): int => ($descending ? strcasecmp($b->typeTitle, $a->typeTitle) : strcasecmp($a->typeTitle, $b->typeTitle)) ?: $byName($a, $b),
            default => static function (ScheduleRow $a, ScheduleRow $b) use ($descending, $byName): int {
                $group = static fn (ScheduleRow $row): int => $row->next !== null ? 0 : ($row->paused() ? 1 : 2);
                if ($group($a) !== $group($b)) {
                    return $group($a) <=> $group($b);
                }
                if ($a->next !== null && $b->next !== null && $a->next != $b->next) {
                    return $descending ? $b->next <=> $a->next : $a->next <=> $b->next;
                }

                return $byName($a, $b);
            },
        });

        return $rows;
    }

    /**
     * Sets the schedule of the profiles to the same (null: none). What a profile has besides its schedule is kept; no connection test is made, a
     * schedule has nothing to do with it. A profile whose schedule is already so is not written, so that its state in the scheduler stays.
     *
     * @param list<string> $ids
     *
     * @return list<ScheduleChange>
     */
    public function set(array $ids, ?ScheduleSettings $schedule): array
    {
        return $this->change($ids, static fn (?ScheduleSettings $current): ?ScheduleSettings => $schedule, false);
    }

    /**
     * Pauses or resumes the schedules of the profiles; a profile without a schedule has nothing to pause.
     *
     * @param list<string> $ids
     *
     * @return list<ScheduleChange>
     */
    public function pause(array $ids, bool $paused): array
    {
        return $this->change($ids, static fn (?ScheduleSettings $current): ?ScheduleSettings => $current?->withPaused($paused), true);
    }

    /**
     * @param list<string>                                          $ids
     * @param callable(?ScheduleSettings): ?ScheduleSettings        $transform the new schedule for the one a profile has
     * @param bool                                                  $needsOne  a profile without a schedule is left alone
     *
     * @return list<ScheduleChange>
     */
    private function change(array $ids, callable $transform, bool $needsOne): array
    {
        $changes = [];
        foreach (array_values(array_unique($ids)) as $id) {
            try {
                $profile = $this->profiles->load($id);
            } catch (ProfileException $e) {
                $changes[] = new ScheduleChange($id, $id, ScheduleChange::MISSING, message: $e->getMessage());

                continue;
            }
            $before = $profile->schedule;
            if ($needsOne && $before === null) {
                $changes[] = new ScheduleChange($id, $profile->name, ScheduleChange::NOTHING, message: 'has no schedule');

                continue;
            }
            $after = $transform($before);
            if ($before?->toArray() === $after?->toArray()) {
                $changes[] = new ScheduleChange($id, $profile->name, ScheduleChange::UNCHANGED, $before, $after);

                continue;
            }
            $result = $this->profiles->store($profile->with(['schedule' => $after?->toArray()]), test: false);
            $changes[] = $result->stored
                ? new ScheduleChange($id, $profile->name, ScheduleChange::CHANGED, $before, $after)
                : new ScheduleChange($id, $profile->name, ScheduleChange::FAILED, $before, $after, $result->error ?? 'The profile could not be stored.');
        }

        return $changes;
    }

    /**
     * @param list<ScheduleRow> $rows
     *
     * @return list<ScheduleRow>
     */
    private function withOverlaps(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $near = [];
            if ($row->next !== null) {
                foreach ($rows as $other) {
                    if ($other !== $row && $other->next !== null && abs($other->next->getTimestamp() - $row->next->getTimestamp()) < self::OVERLAP_MINUTES * 60) {
                        $near[] = $other->profile->name;
                    }
                }
                sort($near, SORT_NATURAL | SORT_FLAG_CASE);
            }
            $out[] = new ScheduleRow($row->profile, $row->typeTitle, $row->schedule, $row->next, $row->last, $near);
        }

        return $out;
    }

    private function typeTitle(string $type): string
    {
        try {
            return $this->plugins->get($type)->title();
        } catch (PluginError) {
            return $type;
        }
    }
}
