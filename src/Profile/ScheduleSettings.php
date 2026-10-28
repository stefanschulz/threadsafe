<?php

declare(strict_types=1);

namespace ThreadSafe\Profile;

use ThreadSafe\Schedule\CronExpression;

/**
 * When a profile is backed up by itself (docs/PHASE8.md 3.1, 3.2): every few hours, every day, on chosen weekdays or on a day of the month.
 *
 * ```
 * {"every": "hours", "interval": 6, "minute": 15}
 * {"every": "day", "at": "03:30"}
 * {"every": "week", "on": ["mon", "thu"], "at": "02:15"}
 * {"every": "month", "day": 1, "at": "04:00"}
 * ```
 *
 * and `"paused": true` next to any of them. The engine reads the schedule as a cron expression ({@see cron()}); nobody has to write one.
 * The time zone is a setting of the installation, not of the profile (docs/PHASE5.md 3.1).
 */
final readonly class ScheduleSettings
{
    public const string HOURS = 'hours';

    public const string DAY = 'day';

    public const string WEEK = 'week';

    public const string MONTH = 'month';

    /** The numbers of hours that divide a day. */
    public const array INTERVALS = [1, 2, 3, 4, 6, 8, 12];

    /** The days of the week, Monday first: name => the word. */
    public const array WEEKDAYS = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];

    /** The last day of the month that every month has. */
    public const int LAST_DAY = 28;

    private const array KEYS = [
        self::HOURS => ['every', 'interval', 'minute', 'paused'],
        self::DAY => ['every', 'at', 'paused'],
        self::WEEK => ['every', 'on', 'at', 'paused'],
        self::MONTH => ['every', 'day', 'at', 'paused'],
    ];

    /**
     * @param list<string> $on weekdays (`mon` ... `sun`), Monday first; only for {@see WEEK}
     */
    private function __construct(
        public string $every,
        public int $interval,
        public int $hour,
        public int $minute,
        public array $on,
        public int $day,
        public bool $paused,
    ) {
    }

    // ------------------------------------------------------------------------------------------------- making one

    /**
     * @throws ProfileException a value is not one that a schedule can have
     */
    public static function hours(int $interval, int $minute = 0, bool $paused = false): self
    {
        if (!in_array($interval, self::INTERVALS, true)) {
            throw new ProfileException('"schedule.interval" must be one of ' . implode(', ', self::INTERVALS) . ' (hours that divide a day).');
        }

        return new self(self::HOURS, $interval, 0, self::minute($minute), [], 0, $paused);
    }

    /**
     * @throws ProfileException
     */
    public static function daily(int $hour, int $minute, bool $paused = false): self
    {
        return new self(self::DAY, 0, self::hour($hour), self::minute($minute), [], 0, $paused);
    }

    /**
     * @param array<mixed> $on the names of the days, in any order
     *
     * @throws ProfileException
     */
    public static function weekly(array $on, int $hour, int $minute, bool $paused = false): self
    {
        $days = [];
        foreach ($on as $name) {
            if (!is_string($name) || !isset(self::WEEKDAYS[$name])) {
                throw new ProfileException('"schedule.on" must list days of the week: ' . implode(', ', array_keys(self::WEEKDAYS)) . '.');
            }
            $days[$name] = true;
        }
        if ($days === []) {
            throw new ProfileException('"schedule.on" must name at least one day of the week.');
        }

        return new self(self::WEEK, 0, self::hour($hour), self::minute($minute), array_values(array_filter(array_keys(self::WEEKDAYS), static fn (string $name): bool => isset($days[$name]))), 0, $paused);
    }

    /**
     * @throws ProfileException
     */
    public static function monthly(int $day, int $hour, int $minute, bool $paused = false): self
    {
        if ($day < 1 || $day > self::LAST_DAY) {
            throw new ProfileException('"schedule.day" must be a day from 1 to ' . self::LAST_DAY . ' (the 29th to the 31st would skip months).');
        }

        return new self(self::MONTH, 0, self::hour($hour), self::minute($minute), [], $day, $paused);
    }

    /**
     * Reads the object of the profile file.
     *
     * @throws ProfileException the data is not a valid schedule
     */
    public static function fromArray(mixed $data): ?self
    {
        if ($data === null) {
            return null;
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ProfileException('"schedule" must be null or an object such as {"every": "day", "at": "03:30"}.');
        }
        $every = $data['every'] ?? null;
        if ($every === null) {
            if (array_diff(array_keys($data), ['every']) !== []) {
                throw new ProfileException('"schedule.every" is missing: it must be one of ' . implode(', ', array_keys(self::KEYS)) . '.');
            }

            return null;
        }
        if (!is_string($every) || !isset(self::KEYS[$every])) {
            throw new ProfileException('"schedule.every" must be one of ' . implode(', ', array_keys(self::KEYS)) . '.');
        }
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::KEYS[$every], true)) {
                throw new ProfileException("Unknown field \"$key\" in \"schedule\" (known for \"$every\": " . implode(', ', self::KEYS[$every]) . ').');
            }
        }
        $paused = $data['paused'] ?? false;
        if (!is_bool($paused)) {
            throw new ProfileException('"schedule.paused" must be true or false.');
        }

        if ($every === self::HOURS) {
            return self::hours(self::integer($data, 'interval'), self::integer($data, 'minute', 0), $paused);
        }
        [$hour, $minute] = self::time($data['at'] ?? null);

        return match ($every) {
            self::DAY => self::daily($hour, $minute, $paused),
            self::WEEK => self::weekly(is_array($data['on'] ?? null) && array_is_list($data['on']) ? $data['on'] : throw new ProfileException('"schedule.on" must be a list of days of the week, such as ["mon", "thu"].'), $hour, $minute, $paused),
            default => self::monthly(self::integer($data, 'day'), $hour, $minute, $paused),
        };
    }

    /**
     * Reads the text that the command line takes (docs/PHASE8.md 3.5): `every 6 hours at :15`, `hourly`, `daily at 03:30`,
     * `weekly on mon,thu at 02:15`, `monthly on 1 at 04:00`; the words `on` and `at` may be left out.
     *
     * @throws ProfileException the text is none of these
     */
    public static function parse(string $text): self
    {
        $normal = trim(preg_replace('/\s+/', ' ', strtolower($text)) ?? '');
        $time = '(\d{1,2}:\d{2})';
        if (preg_match('/^(?:hourly|every hour|every (\d+) hours?)(?: at :?(\d{1,2})| :(\d{1,2}))?$/', $normal, $m) === 1) {
            return self::hours(($m[1] ?? '') === '' ? 1 : (int) $m[1], (int) (($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? 0)));
        }
        if (preg_match("/^(?:daily|every day)(?: at)? $time$/", $normal, $m) === 1) {
            return self::daily(...self::time($m[1]));
        }
        if (preg_match("/^(?:weekly|every week)(?: on)? ([a-z, ]+?)(?: at)? $time$/", $normal, $m) === 1) {
            $on = [];
            foreach (preg_split('/[ ,]+/', $m[1]) ?: [] as $word) {
                $name = substr($word, 0, 3);
                if ($word === '' || !isset(self::WEEKDAYS[$name]) || (strlen($word) > 3 && !str_starts_with(strtolower(self::WEEKDAYS[$name]), $word))) {
                    throw new ProfileException("\"$word\" is not a day of the week (mon, tue, wed, thu, fri, sat, sun).");
                }
                $on[] = $name;
            }

            return self::weekly($on, ...self::time($m[2]));
        }
        if (preg_match("/^(?:monthly|every month)(?: on)? (\d{1,2})(?: at)? $time$/", $normal, $m) === 1) {
            return self::monthly((int) $m[1], ...self::time($m[2]));
        }

        throw new ProfileException('"' . trim($text) . '" is not a schedule. Use one of: "every 6 hours at :15", "daily at 03:30", "weekly on mon,thu at 02:15", "monthly on 1 at 04:00".');
    }

    // ------------------------------------------------------------------------------------------------- using one

    /**
     * @return array<string, mixed> the object of the profile file
     */
    public function toArray(): array
    {
        $data = ['every' => $this->every];
        $at = sprintf('%02d:%02d', $this->hour, $this->minute);
        $data += match ($this->every) {
            self::HOURS => ['interval' => $this->interval, 'minute' => $this->minute],
            self::DAY => ['at' => $at],
            self::WEEK => ['on' => $this->on, 'at' => $at],
            default => ['day' => $this->day, 'at' => $at],
        };
        if ($this->paused) {
            $data['paused'] = true;
        }

        return $data;
    }

    /** Whether the scheduler may start backups for it (it is not paused). */
    public function active(): bool
    {
        return !$this->paused;
    }

    public function withPaused(bool $paused): self
    {
        return new self($this->every, $this->interval, $this->hour, $this->minute, $this->on, $this->day, $paused);
    }

    /** The same schedule, without looking at whether it is paused (to see whether two differ in when they run). */
    public function sameTimes(self $other): bool
    {
        return $this->cron() === $other->cron();
    }

    /** The cron expression that the engine runs on (five fields, normalised). */
    public function cron(): string
    {
        return match ($this->every) {
            self::HOURS => $this->minute . ' ' . ($this->interval === 1 ? '*' : '*/' . $this->interval) . ' * * *',
            self::DAY => $this->minute . ' ' . $this->hour . ' * * *',
            self::WEEK => $this->minute . ' ' . $this->hour . ' * * ' . implode(',', $this->on),
            default => $this->minute . ' ' . $this->hour . ' ' . $this->day . ' * *',
        };
    }

    public function expression(): CronExpression
    {
        return CronExpression::parse($this->cron());
    }

    /** The text form that {@see parse()} reads and the command line takes: `daily at 03:30`. */
    public function text(): string
    {
        $at = sprintf('%02d:%02d', $this->hour, $this->minute);

        return match ($this->every) {
            self::HOURS => ($this->interval === 1 ? 'hourly' : 'every ' . $this->interval . ' hours') . ' at :' . sprintf('%02d', $this->minute),
            self::DAY => 'daily at ' . $at,
            self::WEEK => 'weekly on ' . implode(',', $this->on) . ' at ' . $at,
            default => 'monthly on ' . $this->day . ' at ' . $at,
        };
    }

    /** The schedule in words: "Every day at 03:30". Whether it is paused is not part of it. */
    public function describe(): string
    {
        $at = sprintf('%02d:%02d', $this->hour, $this->minute);

        return match ($this->every) {
            self::HOURS => ($this->interval === 1 ? 'Every hour' : 'Every ' . $this->interval . ' hours') . ' at :' . sprintf('%02d', $this->minute),
            self::DAY => 'Every day at ' . $at,
            self::WEEK => count($this->on) === count(self::WEEKDAYS)
                ? 'Every day at ' . $at
                : 'Every ' . self::enumerate(array_map(static fn (string $name): string => self::WEEKDAYS[$name], $this->on)) . ' at ' . $at,
            default => 'On the ' . self::ordinal($this->day) . ' of every month at ' . $at,
        };
    }

    // ------------------------------------------------------------------------------------------------- helpers

    private static function hour(int $hour): int
    {
        return $hour >= 0 && $hour <= 23 ? $hour : throw new ProfileException('The hour of "schedule.at" must be from 0 to 23.');
    }

    private static function minute(int $minute): int
    {
        return $minute >= 0 && $minute <= 59 ? $minute : throw new ProfileException('"schedule.minute" and the minutes of "schedule.at" must be from 0 to 59.');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function integer(array $data, string $key, ?int $default = null): int
    {
        $value = $data[$key] ?? $default;

        return is_int($value) ? $value : throw new ProfileException("\"schedule.$key\" must be a whole number.");
    }

    /**
     * @return array{int, int} hour and minute
     */
    private static function time(mixed $text): array
    {
        if (!is_string($text) || preg_match('/^(\d{1,2}):(\d{2})$/', $text, $m) !== 1) {
            throw new ProfileException('"schedule.at" must be a time of day such as "03:30".');
        }

        return [self::hour((int) $m[1]), self::minute((int) $m[2])];
    }

    /**
     * @param list<string> $words
     */
    private static function enumerate(array $words): string
    {
        $last = array_pop($words);

        return $words === [] ? (string) $last : implode(', ', $words) . ' and ' . $last;
    }

    private static function ordinal(int $number): string
    {
        $suffix = in_array($number % 100, [11, 12, 13], true) ? 'th' : match ($number % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };

        return $number . $suffix;
    }
}
