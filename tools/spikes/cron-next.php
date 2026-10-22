<?php

/**
 * SPIKE (phase 5, WP 1b) - throw-away script, not part of the program.
 *
 * Question: can "the next instant of a five-field cron expression in a time zone" be computed with plain arithmetic on the wall clock
 * and DateTimeZone, correct across daylight saving time? The prototype is compared with a brute-force reference that looks at every
 * minute of a whole year: the instants at which the local clock matches the expression. The two may differ only where the rules of the
 * plan say so: a time in the gap (it does not exist: the prototype runs it at the first instant after the gap) and the second pass of
 * the hour that exists twice (the prototype runs once, at the first).
 *
 *   php tools/spikes/cron-next.php [year]
 */
declare(strict_types=1);

final class Cron
{
    /** @var array<string, list<int>> */
    private array $fields = [];

    private bool $domStar;

    private bool $dowStar;

    public function __construct(string $expression)
    {
        $expression = ['@hourly' => '0 * * * *', '@daily' => '0 0 * * *', '@midnight' => '0 0 * * *', '@weekly' => '0 0 * * 0', '@monthly' => '0 0 1 * *'][$expression] ?? $expression;
        $parts = preg_split('/\s+/', trim($expression)) ?: [];
        if (count($parts) !== 5) {
            throw new InvalidArgumentException('A cron expression has five fields.');
        }
        $names = [
            'month' => ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12],
            'dow' => ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6],
        ];
        $this->fields = [
            'minute' => $this->parse($parts[0], 0, 59, []),
            'hour' => $this->parse($parts[1], 0, 23, []),
            'dom' => $this->parse($parts[2], 1, 31, []),
            'month' => $this->parse($parts[3], 1, 12, $names['month']),
            'dow' => $this->parse($parts[4], 0, 7, $names['dow']),
        ];
        $this->fields['dow'] = array_values(array_unique(array_map(static fn (int $d): int => $d % 7, $this->fields['dow'])));
        $this->domStar = str_starts_with($parts[2], '*');
        $this->dowStar = str_starts_with($parts[4], '*');
    }

    /** @param array<string, int> $names @return list<int> */
    private function parse(string $field, int $min, int $max, array $names): array
    {
        $values = [];
        foreach (explode(',', strtolower($field)) as $item) {
            $step = 1;
            if (str_contains($item, '/')) {
                [$item, $stepText] = explode('/', $item, 2);
                $step = (int) $stepText;
                if ($step < 1) {
                    throw new InvalidArgumentException("Bad step in \"$field\".");
                }
            }
            if ($item === '*') {
                [$from, $to] = [$min, $max === 7 ? 6 : $max];
            } elseif (str_contains($item, '-')) {
                [$a, $b] = explode('-', $item, 2);
                [$from, $to] = [$names[$a] ?? (int) $a, $names[$b] ?? (int) $b];
            } else {
                $from = $names[$item] ?? (int) $item;
                $to = str_contains($field, '/') ? ($max === 7 ? 6 : $max) : $from;
            }
            if ($from < $min || $to > $max || $from > $to) {
                throw new InvalidArgumentException("\"$field\" is out of range ($min-$max).");
            }
            for ($v = $from; $v <= $to; $v += $step) {
                $values[] = $v;
            }
        }
        sort($values);

        return array_values(array_unique($values));
    }

    /** (ext-calendar is not on every host) */
    private static function daysIn(int $month, int $year): int
    {
        return $month === 2 ? (($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0 ? 29 : 28) : (in_array($month, [4, 6, 9, 11], true) ? 30 : 31);
    }

    private function dayMatches(int $y, int $m, int $d): bool
    {
        $dow = (int) (new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, $d), new DateTimeZone('UTC')))->format('w');
        $dom = in_array($d, $this->fields['dom'], true);
        $week = in_array($dow, $this->fields['dow'], true);

        return match (true) {
            $this->domStar && $this->dowStar => true,
            $this->domStar => $week,
            $this->dowStar => $dom,
            default => $dom || $week,   // both restricted: either (Vixie cron)
        };
    }

    /** Does the local clock at this instant match? (what the reference uses) */
    public function matches(DateTimeImmutable $at): bool
    {
        [$y, $m, $d, $h, $i] = array_map('intval', explode(' ', $at->format('Y n j G i')));

        return in_array($m, $this->fields['month'], true) && $this->dayMatches($y, $m, $d) && in_array($h, $this->fields['hour'], true) && in_array($i, $this->fields['minute'], true);
    }

    /** The first instant after $after at which the expression is due, in the time zone of $after. */
    public function next(DateTimeImmutable $after): DateTimeImmutable
    {
        $zone = $after->getTimezone();
        [$y, $m, $d, $h, $i] = array_map('intval', explode(' ', $after->format('Y n j G i')));
        ++$i;   // wall clock minutes, one step past the one we are in
        for ($guard = 0; $guard < 400000; ++$guard) {
            // normalise the carry
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
            if (!in_array($m, $this->fields['month'], true)) {
                [$d, $h, $i] = [1, 0, 0];
                ++$m;
                continue;
            }
            if (!$this->dayMatches($y, $m, $d)) {
                [$h, $i] = [0, 0];
                ++$d;
                continue;
            }
            if (!in_array($h, $this->fields['hour'], true)) {
                $i = 0;
                ++$h;
                continue;
            }
            if (!in_array($i, $this->fields['minute'], true)) {
                ++$i;
                continue;
            }
            $instant = $this->resolve($y, $m, $d, $h, $i, $zone);
            if ($instant > $after) {
                return $instant;
            }
            ++$i;
        }

        throw new RuntimeException('No next run within 400,000 steps (an expression that never matches, e.g. 31 February).');
    }

    /**
     * A wall clock time as an instant, decided here and not left to PHP (which resolves a repeated time differently per zone): the
     * first occurrence if the time exists twice, and the first instant after the gap if it does not exist.
     */
    private function resolve(int $y, int $m, int $d, int $h, int $i, DateTimeZone $zone): DateTimeImmutable
    {
        $wall = (new DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:00', $y, $m, $d, $h, $i), new DateTimeZone('UTC')))->getTimestamp();
        $offsetAt = static fn (int $ts): int => $zone->getOffset(new DateTimeImmutable('@' . $ts));
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

        // in a gap: the wall time lies in [ts + offset before, ts + offset after) of a transition; that transition is the answer
        $previous = null;
        foreach ($zone->getTransitions($wall - 3 * 86400, $wall + 3 * 86400) as $transition) {
            if ($previous !== null && $wall >= $transition['ts'] + $previous && $wall < $transition['ts'] + $transition['offset']) {
                return (new DateTimeImmutable('@' . $transition['ts']))->setTimezone($zone);
            }
            $previous = $transition['offset'];
        }

        throw new RuntimeException("Cannot place $y-$m-$d $h:$i in the time zone.");
    }
}

echo 'PHP ', PHP_VERSION, "\n";
$year = (int) ($argv[1] ?? 2026);

// 1. a few expressions by hand
$utc = new DateTimeZone('UTC');
$cases = [
    ['30 3 * * *', '2026-10-22 03:30:00', '2026-10-23 03:30:00'],
    ['*/15 * * * *', '2026-10-22 10:07:00', '2026-10-22 10:15:00'],
    ['0 0 29 2 *', '2026-03-01 00:00:00', '2028-02-29 00:00:00'],
    ['0 12 * * 1-5', '2026-10-23 12:00:00', '2026-10-26 12:00:00'],
    ['0 0 13 * 5', '2026-10-22 00:00:00', '2026-10-23 00:00:00'],
    ['@weekly', '2026-10-22 00:00:00', '2026-10-25 00:00:00'],
    ['0 9 1,15 * *', '2026-12-15 09:00:00', '2027-01-01 09:00:00'],
    ['5 4 * jan,jul mon', '2026-06-30 00:00:00', '2026-07-06 04:05:00'],
];
$wrong = [];
foreach ($cases as [$expression, $from, $expected]) {
    $got = (new Cron($expression))->next(new DateTimeImmutable($from, $utc))->format('Y-m-d H:i:s');
    if ($got !== $expected) {
        $wrong[] = "$expression after $from: $got, expected $expected";
    }
}
echo '1. by hand: ', $wrong === [] ? count($cases) . ' expressions correct' : "WRONG:\n   " . implode("\n   ", $wrong), "\n";

// 2. against the brute-force reference over a whole year
$expressions = ['*/15 * * * *', '30 3 * * *', '30 2 * * *', '0 1 * * *', '0 * * * *', '0 0 29 2 *', '0 12 * * 1-5', '0 0 1,15 * *', '0 0 13 * 5', '15 4 * * 0', '45 23 * * *'];
$zones = ['UTC', 'Europe/Berlin', 'America/New_York', 'Australia/Lord_Howe', 'Asia/Kolkata'];
printf("2. the whole year %d, every minute, against the prototype (differences allowed only in a gap and in the repeated hour):\n", $year);
$totalBad = 0;
foreach ($zones as $zoneName) {
    $zone = new DateTimeZone($zoneName);
    foreach ($expressions as $expression) {
        $cron = new Cron($expression);
        $start = (new DateTimeImmutable("$year-01-01 00:00:00", new DateTimeZone('UTC')))->getTimestamp() - 86400 * 2;
        $end = $start + 86400 * 369;
        $reference = [];
        for ($ts = $start; $ts < $end; $ts += 60) {
            if ($cron->matches((new DateTimeImmutable('@' . $ts))->setTimezone($zone))) {
                $reference[$ts] = true;
            }
        }
        // the prototype's chain
        $t = microtime(true);
        $chain = [];
        $at = (new DateTimeImmutable('@' . ($start - 60)))->setTimezone($zone);
        while (true) {
            $at = $cron->next($at);
            if ($at->getTimestamp() >= $end) {
                break;
            }
            $chain[$at->getTimestamp()] = true;
        }
        $elapsed = microtime(true) - $t;
        $onlyReference = array_diff_key($reference, $chain);
        $onlyChain = array_diff_key($chain, $reference);
        // classify: the reference has an instant the prototype lacks: must be the second pass of a repeated hour
        $unexplained = 0;
        foreach (array_keys($onlyReference) as $ts) {
            $earlier = $ts - 3600;
            $sameLocal = (new DateTimeImmutable('@' . $ts))->setTimezone($zone)->format('Y-m-d H:i') === (new DateTimeImmutable('@' . $earlier))->setTimezone($zone)->format('Y-m-d H:i');
            $shift = $zone->getOffset(new DateTimeImmutable('@' . $ts)) - $zone->getOffset(new DateTimeImmutable('@' . $earlier));
            // the same wall clock time one hour (or half an hour) earlier: the repeated hour
            $repeated = false;
            foreach ([1800, 3600] as $gap) {
                $e = (new DateTimeImmutable('@' . ($ts - $gap)))->setTimezone($zone)->format('Y-m-d H:i');
                $repeated = $repeated || $e === (new DateTimeImmutable('@' . $ts))->setTimezone($zone)->format('Y-m-d H:i');
            }
            if (!$repeated) {
                ++$unexplained;
                if (getenv('DEBUG')) {
                    echo '      unexplained reference-only: ', gmdate('c', $ts), ' = ', (new DateTimeImmutable('@' . $ts))->setTimezone($zone)->format('c'), "
";
                }
            }
        }
        // the prototype has an instant the reference lacks: must be the first instant after a gap
        foreach (array_keys($onlyChain) as $ts) {
            $isTransition = false;
            foreach ($zone->getTransitions($ts - 10, $ts + 10) as $k => $tr) {
                $isTransition = $isTransition || ($k > 0 && $tr['ts'] === $ts);
            }
            if (!$isTransition) {
                ++$unexplained;
            }
        }
        $totalBad += $unexplained;
        if ($onlyReference !== [] || $onlyChain !== [] || $unexplained > 0 || $elapsed > 2.0) {
            printf("   %-20s %-16s runs %5d  reference-only %d (repeated hour), prototype-only %d (after a gap), UNEXPLAINED %d  [%.2f s for the chain]\n", $zoneName, $expression, count($chain), count($onlyReference), count($onlyChain), $unexplained, $elapsed);
        }
    }
}
echo '   ', count($zones) * count($expressions), ' combinations checked; unexplained differences: ', $totalBad, "\n";
