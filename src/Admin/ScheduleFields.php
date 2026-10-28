<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\ScheduleSettings;
use ThreadSafe\Web\Html;
use ThreadSafe\Web\Request;

/**
 * The fields that set a schedule (docs/PHASE8.md 3.4), shared by the page of all schedules and the form of a profile: how often, and what that
 * needs (the hours and the minute, the time of day, the days of the week, the day of the month), and whether the schedule is paused. The form
 * holds only text; {@see read()} turns it into a {@see ScheduleSettings} and says in words what is wrong.
 */
final class ScheduleFields
{
    /** The value of "how often" that leaves the schedule of a profile as it is (the form for several profiles). */
    public const string KEEP = 'keep';

    /** The name of the field that says how often. */
    public const string EVERY = 'schedule_every';

    /** The text fields, in the order of the form. */
    private const array TEXT = ['schedule_every', 'schedule_interval', 'schedule_minute', 'schedule_time', 'schedule_day'];

    /**
     * @return array<string, string> the values of a schedule (none: "not scheduled" and the usual start values), for the form
     */
    public static function values(?ScheduleSettings $schedule): array
    {
        $values = ['schedule_every' => '', 'schedule_interval' => '6', 'schedule_minute' => '0', 'schedule_time' => '03:00', 'schedule_day' => '1', 'schedule_paused' => '0'];
        foreach (array_keys(ScheduleSettings::WEEKDAYS) as $name) {
            $values['schedule_on_' . $name] = '0';
        }
        if ($schedule === null) {
            return $values;
        }
        $values['schedule_every'] = $schedule->every;
        $values['schedule_paused'] = $schedule->paused ? '1' : '0';
        $time = sprintf('%02d:%02d', $schedule->hour, $schedule->minute);
        match ($schedule->every) {
            ScheduleSettings::HOURS => [$values['schedule_interval'], $values['schedule_minute']] = [(string) $schedule->interval, (string) $schedule->minute],
            ScheduleSettings::DAY => $values['schedule_time'] = $time,
            ScheduleSettings::WEEK => self::weekdays($values, $schedule->on, $time),
            default => [$values['schedule_day'], $values['schedule_time']] = [(string) $schedule->day, $time],
        };

        return $values;
    }

    /**
     * @param array<string, string> $values
     * @param list<string>          $on
     */
    private static function weekdays(array &$values, array $on, string $time): void
    {
        $values['schedule_time'] = $time;
        foreach ($on as $name) {
            $values['schedule_on_' . $name] = '1';
        }
    }

    /**
     * The fields of the request, if the form has them (a form without the schedule sends none of them, and then the schedule stays as it is).
     * A check box that is not ticked is not sent: here it becomes "0".
     *
     * @return array<string, string>
     */
    public static function fromRequest(Request $request): array
    {
        if ($request->post('schedule_every') === null) {
            return [];
        }
        $values = [];
        foreach (self::TEXT as $name) {
            $values[$name] = trim($request->post($name) ?? '');
        }
        $values['schedule_paused'] = $request->post('schedule_paused') === '1' ? '1' : '0';
        foreach (array_keys(ScheduleSettings::WEEKDAYS) as $day) {
            $values['schedule_on_' . $day] = $request->post('schedule_on_' . $day) === '1' ? '1' : '0';
        }

        return $values;
    }

    /**
     * Turns the values of the form into a schedule.
     *
     * @param array<string, string> $values
     * @param ScheduleSettings|null $current what the profile has now (the answer for {@see KEEP})
     *
     * @return array{settings: ScheduleSettings|null, errors: list<string>}
     */
    public static function read(array $values, ?ScheduleSettings $current = null): array
    {
        $every = $values['schedule_every'] ?? '';
        if ($every === self::KEEP) {
            return ['settings' => $current, 'errors' => []];
        }
        if ($every === '') {
            return ['settings' => null, 'errors' => []];
        }
        $paused = ($values['schedule_paused'] ?? '0') === '1';
        $errors = [];
        $time = null;
        if (in_array($every, [ScheduleSettings::DAY, ScheduleSettings::WEEK, ScheduleSettings::MONTH], true)) {
            if (preg_match('/^(\d{1,2}):(\d{2})$/', $values['schedule_time'] ?? '', $m) !== 1 || (int) $m[1] > 23 || (int) $m[2] > 59) {
                $errors[] = 'The time of day is a time such as 03:30.';
            } else {
                $time = [(int) $m[1], (int) $m[2]];
            }
        }

        try {
            switch ($every) {
                case ScheduleSettings::HOURS:
                    $interval = $values['schedule_interval'] ?? '';
                    $minute = $values['schedule_minute'] ?? '';
                    if (!in_array((int) $interval, ScheduleSettings::INTERVALS, true) || (string) (int) $interval !== $interval) {
                        $errors[] = 'Choose how many hours lie between two backups: ' . implode(', ', ScheduleSettings::INTERVALS) . '.';
                    }
                    if (preg_match('/^\d{1,2}$/', $minute) !== 1 || (int) $minute > 59) {
                        $errors[] = 'The minute of the hour is a number from 0 to 59.';
                    }

                    return $errors === [] ? ['settings' => ScheduleSettings::hours((int) $interval, (int) $minute, $paused), 'errors' => []] : ['settings' => null, 'errors' => $errors];
                case ScheduleSettings::DAY:
                    return $time === null ? ['settings' => null, 'errors' => $errors] : ['settings' => ScheduleSettings::daily($time[0], $time[1], $paused), 'errors' => []];
                case ScheduleSettings::WEEK:
                    $on = array_values(array_filter(array_keys(ScheduleSettings::WEEKDAYS), static fn (string $name): bool => ($values['schedule_on_' . $name] ?? '0') === '1'));
                    if ($on === []) {
                        $errors[] = 'Choose at least one day of the week.';
                    }

                    return $errors === [] && $time !== null ? ['settings' => ScheduleSettings::weekly($on, $time[0], $time[1], $paused), 'errors' => []] : ['settings' => null, 'errors' => $errors];
                case ScheduleSettings::MONTH:
                    $day = $values['schedule_day'] ?? '';
                    if (preg_match('/^\d{1,2}$/', $day) !== 1 || (int) $day < 1 || (int) $day > ScheduleSettings::LAST_DAY) {
                        $errors[] = 'The day of the month is a number from 1 to ' . ScheduleSettings::LAST_DAY . ' (the 29th to the 31st would skip months).';
                    }

                    return $errors === [] && $time !== null ? ['settings' => ScheduleSettings::monthly((int) $day, $time[0], $time[1], $paused), 'errors' => []] : ['settings' => null, 'errors' => $errors];
                default:
                    return ['settings' => null, 'errors' => ['Choose how often the backup is made.']];
            }
        } catch (ProfileException $e) {
            return ['settings' => null, 'errors' => [$e->getMessage()]];
        }
    }

    /**
     * The fields as HTML.
     *
     * @param array<string, string> $values   as {@see values()}
     * @param bool                  $multiple the form is for several profiles: "leave as it is" is a choice, and is the one that is made at first
     */
    public static function render(array $values, bool $multiple = false): string
    {
        $values += self::values(null);
        $every = $values['schedule_every'];
        $choices = ($multiple ? [self::KEEP => 'Leave as it is'] : [])
            + ['' => 'Not scheduled', ScheduleSettings::HOURS => 'Every few hours', ScheduleSettings::DAY => 'Every day', ScheduleSettings::WEEK => 'On chosen days of the week', ScheduleSettings::MONTH => 'On a day of the month'];

        $intervals = [];
        foreach (ScheduleSettings::INTERVALS as $hours) {
            $intervals[(string) $hours] = $hours === 1 ? 'every hour' : 'every ' . $hours . ' hours';
        }
        $minutes = [];
        for ($minute = 0; $minute < 60; $minute += 5) {
            $minutes[(string) $minute] = ':' . sprintf('%02d', $minute);
        }
        if (!isset($minutes[$values['schedule_minute']])) {
            $minutes[$values['schedule_minute']] = ':' . sprintf('%02d', (int) $values['schedule_minute']);
            ksort($minutes, SORT_NUMERIC);
        }
        $days = [];
        for ($day = 1; $day <= ScheduleSettings::LAST_DAY; ++$day) {
            $days[(string) $day] = (string) $day;
        }
        $weekdays = '';
        foreach (ScheduleSettings::WEEKDAYS as $name => $word) {
            $weekdays .= '<label class="inline-check"><input type="checkbox" name="schedule_on_' . $name . '" value="1"' . ($values['schedule_on_' . $name] === '1' ? ' checked' : '') . '> ' . Html::e(substr($word, 0, 3)) . '</label>';
        }

        return '<div class="schedule-fields" data-schedule-fields>'
            . View::select('schedule_every', 'How often', $choices, $every, '', ['data-schedule-every' => ''])
            . '<fieldset class="schedule-group" data-for="hours"><legend>Every few hours</legend>'
            . View::select('schedule_interval', 'Every', $intervals, $values['schedule_interval'])
            . View::select('schedule_minute', 'At minute', $minutes, $values['schedule_minute'], 'The minute of the hour at which the backup starts.')
            . '</fieldset>'
            . '<fieldset class="schedule-group" data-for="day week month"><legend>Time of day</legend>'
            . View::field('schedule_time', 'At', 'time', $values['schedule_time'], ['step' => '300'], 'In steps of five minutes.')
            . '</fieldset>'
            . '<fieldset class="schedule-group" data-for="week"><legend>On chosen days of the week</legend><div class="weekdays">' . $weekdays . '</div></fieldset>'
            . '<fieldset class="schedule-group" data-for="month"><legend>On a day of the month</legend>'
            . View::select('schedule_day', 'Day', $days, $values['schedule_day'], 'From 1 to 28: the 29th to the 31st would skip months.')
            . '</fieldset>'
            . '<div class="schedule-group" data-for="hours day week month">' . View::checkbox('schedule_paused', 'Paused', $values['schedule_paused'] === '1', 'A paused schedule keeps its settings, but no backup is made by it until it is resumed.') . '</div>'
            . '</div>';
    }
}
