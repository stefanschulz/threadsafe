<?php

declare(strict_types=1);

namespace ThreadSafe\Profile;

use ThreadSafe\Schedule\CronExpression;
use ThreadSafe\Schedule\ScheduleException;

/**
 * When a profile is backed up by itself: `{"cron": "30 3 * * *"}`. The time zone is a setting of the installation, not of the
 * profile (docs/PHASE5.md 3.1).
 */
final readonly class ScheduleSettings
{
    private const array KEYS = ['cron'];

    private function __construct(public string $cron)
    {
    }

    /**
     * @throws ProfileException the data is not a valid schedule
     */
    public static function fromArray(mixed $data): ?self
    {
        if ($data === null) {
            return null;
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ProfileException('"schedule" must be null or an object such as {"cron": "30 3 * * *"}.');
        }
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new ProfileException("Unknown field \"$key\" in \"schedule\" (known: " . implode(', ', self::KEYS) . ').');
            }
        }
        $cron = $data['cron'] ?? null;
        if ($cron === null) {
            return null;
        }
        if (!is_string($cron)) {
            throw new ProfileException('"schedule.cron" must be a cron expression such as "30 3 * * *".');
        }
        try {
            $expression = CronExpression::parse($cron)->expression();
        } catch (ScheduleException $e) {
            throw new ProfileException('"schedule.cron": ' . $e->getMessage(), 0, $e);
        }

        return new self($expression);
    }

    /**
     * @return array{cron: string}
     */
    public function toArray(): array
    {
        return ['cron' => $this->cron];
    }

    public function expression(): CronExpression
    {
        return CronExpression::parse($this->cron);
    }
}
