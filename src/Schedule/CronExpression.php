<?php

declare(strict_types=1);

namespace ThreadSafe\Schedule;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A classic five-field cron expression (minute, hour, day of month, month, day of week) and the arithmetic on it: the next and the previous
 * instant at which it is due, in the time zone of the instant that is given (docs/PHASE5.md 3.2).
 *
 * Fields take `*`, numbers, ranges, lists, steps and names for months and days of the week; the shortcuts are `@hourly`, `@daily`,
 * `@weekly` and `@monthly`. Sunday is 0 or 7. When both day fields are restricted either may match (the rule of Vixie cron); a field that
 * starts with `*` counts as unrestricted, also with a step.
 *
 * All arithmetic is done on the wall clock and the instant is decided here, not by PHP, which resolves a repeated local time differently
 * per zone (docs/PHASE5.md section 8, finding 5): a time that exists twice (the clock goes back) is due once, at its first occurrence; a
 * time that does not exist (the clock jumps forward) is due at the first instant after the gap.
 */
final class CronExpression
{
    private const array SHORTCUTS = ['@hourly' => '0 * * * *', '@daily' => '0 0 * * *', '@weekly' => '0 0 * * 0', '@monthly' => '0 0 1 * *'];

    private const array MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    private const array DAYS = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];

    /** How many years the search goes, in either direction: an expression such as "29 February" needs up to eight. */
    private const int HORIZON_YEARS = 9;

    /**
     * @param list<int> $minutes
     * @param list<int> $hours
     * @param list<int> $days       days of the month
     * @param list<int> $months
     * @param list<int> $weekdays   0 (Sunday) to 6
     * @param bool      $anyDay     the day-of-month field started with `*`
     * @param bool      $anyWeekday the day-of-week field started with `*`
     */
    private function __construct(
        private readonly string $expression,
        private readonly array $minutes,
        private readonly array $hours,
        private readonly array $days,
        private readonly array $months,
        private readonly array $weekdays,
        private readonly bool $anyDay,
        private readonly bool $anyWeekday,
    ) {
    }

    /**
     * @throws ScheduleException the text is not a cron expression, or one that never matches (such as 31 February)
     */
    public static function parse(string $text): self
    {
        $expression = trim($text);
        $fields = preg_split('/\s+/', self::SHORTCUTS[strtolower($expression)] ?? $expression) ?: [];
        if (count($fields) !== 5) {
            throw new ScheduleException("\"$expression\" is not a cron expression: it needs five fields (minute hour day-of-month month day-of-week) or one of @hourly, @daily, @weekly, @monthly.");
        }

        $parsed = new self(
            $expression,
            self::field($fields[0], 'minute', 0, 59),
            self::field($fields[1], 'hour', 0, 23),
            self::field($fields[2], 'day of the month', 1, 31),
            self::field($fields[3], 'month', 1, 12, self::MONTHS),
            array_values(array_unique(array_map(static fn (int $day): int => $day % 7, self::field($fields[4], 'day of the week', 0, 7, self::DAYS)))),
            str_starts_with($fields[2], '*'),
            str_starts_with($fields[4], '*'),
        );
        try {
            $parsed->next(new DateTimeImmutable('2024-01-01 00:00:00', new DateTimeZone('UTC')));
        } catch (ScheduleException) {
            throw new ScheduleException("\"$expression\" never matches a date (for example 31 February).");
        }

        return $parsed;
    }

    /** The expression as it was written. */
    public function expression(): string
    {
        return $this->expression;
    }

    /**
     * The first instant after $after at which the expression is due, in the time zone of $after.
     *
     * @throws ScheduleException there is none within nine years
     */
    public function next(DateTimeImmutable $after): DateTimeImmutable
    {
        $zone = $after->getTimezone();
        [$y, $m, $d, $h, $i] = self::wall($after);
        $limit = $y + self::HORIZON_YEARS;
        ++$i;
        while ($y <= $limit) {
            if ($i > 59) {
                $i = 0;
                ++$h;
            }
            if ($h > 23) {
                $h = 0;
                ++$d;
            }
            if ($d > self::daysIn($m, $y)) {
                $d = 1;
                ++$m;
            }
            if ($m > 12) {
                $m = 1;
                ++$y;
            }
            if (!in_array($m, $this->months, true)) {
                [$d, $h, $i] = [1, 0, 0];
                ++$m;
            } elseif (!$this->dayMatches($y, $m, $d)) {
                [$h, $i] = [0, 0];
                ++$d;
            } elseif (!in_array($h, $this->hours, true)) {
                $i = 0;
                ++$h;
            } elseif (!in_array($i, $this->minutes, true)) {
                ++$i;
            } else {
                $instant = self::resolve($y, $m, $d, $h, $i, $zone);
                if ($instant > $after) {
                    return $instant;
                }
                ++$i;   // the second pass of a repeated hour: this time was already due at its first occurrence
            }
        }

        throw new ScheduleException("\"{$this->expression}\" is not due within the next " . self::HORIZON_YEARS . ' years.');
    }

    /**
     * The latest instant not after $at at which the expression is due, in the time zone of $at; null if there is none within nine years.
     */
    public function previous(DateTimeImmutable $at): ?DateTimeImmutable
    {
        $zone = $at->getTimezone();
        [$y, $m, $d, $h, $i] = self::wall($at);
        $limit = $y - self::HORIZON_YEARS;
        while ($y >= $limit) {
            if ($i < 0) {
                $i = 59;
                --$h;
            }
            if ($h < 0) {
                $h = 23;
                --$d;
            }
            if ($d < 1) {
                --$m;
                if ($m < 1) {
                    $m = 12;
                    --$y;
                }
                $d = self::daysIn($m, $y);
            }
            if (!in_array($m, $this->months, true)) {
                $d = 0;   // the borrow above takes the last day of the month before
                $h = 23;
                $i = 59;
            } elseif (!$this->dayMatches($y, $m, $d)) {
                --$d;
                $h = 23;
                $i = 59;
            } elseif (!in_array($h, $this->hours, true)) {
                --$h;
                $i = 59;
            } elseif (!in_array($i, $this->minutes, true)) {
                --$i;
            } else {
                $instant = self::resolve($y, $m, $d, $h, $i, $zone);
                if ($instant <= $at) {
                    // $at in the second pass of a repeated hour: the later times of the first pass were due before $at as well
                    while (($following = $this->next($instant)) <= $at) {
                        $instant = $following;
                    }

                    return $instant;
                }
                --$i;
            }
        }

        return null;
    }

    private function dayMatches(int $year, int $month, int $day): bool
    {
        $weekday = (int) gmdate('w', self::wallSeconds($year, $month, $day, 0, 0));
        $dayMatches = in_array($day, $this->days, true);
        $weekdayMatches = in_array($weekday, $this->weekdays, true);

        return $this->anyDay || $this->anyWeekday ? $dayMatches && $weekdayMatches : $dayMatches || $weekdayMatches;
    }

    /**
     * @param array<string, int> $names
     *
     * @return list<int>
     */
    private static function field(string $text, string $label, int $min, int $max, array $names = []): array
    {
        $values = [];
        foreach (explode(',', strtolower($text)) as $item) {
            $step = 1;
            $range = $item;
            if (str_contains($item, '/')) {
                [$range, $stepText] = explode('/', $item, 2);
                if (preg_match('/^\d{1,3}$/', $stepText) !== 1 || (int) $stepText < 1) {
                    throw new ScheduleException("The step \"$stepText\" in the $label field \"$text\" must be a positive number.");
                }
                $step = (int) $stepText;
            }
            $top = $max === 7 ? 6 : $max;   // "*" in the day-of-week field is 0-6; 7 is only accepted as a number
            if ($range === '*') {
                [$from, $to] = [$min, $top];
            } elseif (str_contains($range, '-')) {
                $ends = explode('-', $range);
                if (count($ends) !== 2) {
                    throw new ScheduleException("The range \"$range\" in the $label field \"$text\" is not valid.");
                }
                [$from, $to] = [self::number($ends[0], $label, $text, $names), self::number($ends[1], $label, $text, $names)];
            } else {
                $from = self::number($range, $label, $text, $names);
                $to = str_contains($item, '/') ? $top : $from;   // "5/10" runs from 5 to the end of the field
            }
            if ($from < $min || $to > $max || $from > $to) {
                throw new ScheduleException("The $label field \"$text\" is out of range ($min-$max" . ($names !== [] ? ', or a name' : '') . ').');
            }
            for ($value = $from; $value <= $to; $value += $step) {
                $values[] = $value;
            }
        }
        sort($values);

        return array_values(array_unique($values));
    }

    /**
     * @param array<string, int> $names
     */
    private static function number(string $text, string $label, string $field, array $names): int
    {
        if (isset($names[$text])) {
            return $names[$text];
        }
        if (preg_match('/^\d{1,2}$/', $text) !== 1) {
            throw new ScheduleException("\"$text\" in the $label field \"$field\" is not a number" . ($names !== [] ? ' or a name' : '') . '.');
        }

        return (int) $text;
    }

    /**
     * @return array{int, int, int, int, int} year, month, day, hour, minute of the local clock
     */
    private static function wall(DateTimeImmutable $at): array
    {
        return [(int) $at->format('Y'), (int) $at->format('n'), (int) $at->format('j'), (int) $at->format('G'), (int) $at->format('i')];
    }

    /** The wall clock time as if it were UTC: seconds since 1970 that stand for that date and time on the clock. */
    private static function wallSeconds(int $y, int $m, int $d, int $h, int $i): int
    {
        $seconds = gmmktime($h, $i, 0, $m, $d, $y);

        return $seconds === false ? throw new ScheduleException(sprintf('The date %04d-%02d-%02d is out of range.', $y, $m, $d)) : $seconds;
    }

    /** (ext-calendar is not installed everywhere) */
    private static function daysIn(int $month, int $year): int
    {
        return match ($month) {
            2 => ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0 ? 29 : 28,
            4, 6, 9, 11 => 30,
            default => 31,
        };
    }

    /**
     * A wall clock time as an instant: the first occurrence if the time exists twice, and the first instant after the gap if it does
     * not exist.
     */
    private static function resolve(int $y, int $m, int $d, int $h, int $i, DateTimeZone $zone): DateTimeImmutable
    {
        $wall = self::wallSeconds($y, $m, $d, $h, $i);
        $offsetAt = static fn (int $timestamp): int => $zone->getOffset(new DateTimeImmutable('@' . $timestamp));
        $valid = [];
        foreach (array_unique([$offsetAt($wall - 86400), $offsetAt($wall), $offsetAt($wall + 86400)]) as $offset) {
            $instant = $wall - $offset;
            if ($offsetAt($instant) === $offset) {
                $valid[] = $instant;
            }
        }
        if ($valid !== []) {
            return (new DateTimeImmutable('@' . min($valid)))->setTimezone($zone);
        }

        // in a gap: the wall time lies in [ts + offset before, ts + offset after) of a transition, and that transition is the answer
        $previous = null;
        foreach ($zone->getTransitions($wall - 3 * 86400, $wall + 3 * 86400) as $transition) {
            if ($previous !== null && $wall >= $transition['ts'] + $previous && $wall < $transition['ts'] + $transition['offset']) {
                return (new DateTimeImmutable('@' . $transition['ts']))->setTimezone($zone);
            }
            $previous = $transition['offset'];
        }

        throw new ScheduleException(sprintf('Cannot place %04d-%02d-%02d %02d:%02d in the time zone %s.', $y, $m, $d, $h, $i, $zone->getName()));
    }
}
