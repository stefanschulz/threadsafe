<?php

declare(strict_types=1);

namespace ThreadSafe\Profile;

/**
 * How many backups of a profile are kept: at most `keep` of them, none older than `maxAgeDays`, together at most `maxBytes`
 * (docs/PHASE5.md 3.4). Every rule is optional; the rules are applied by the retention of work package 4.
 */
final readonly class RetentionSettings
{
    private const array KEYS = ['keep', 'maxAgeDays', 'maxBytes'];

    private function __construct(public ?int $keep, public ?int $maxAgeDays, public ?int $maxBytes)
    {
    }

    /**
     * @throws ProfileException the data is not valid retention settings
     */
    public static function fromArray(mixed $data): ?self
    {
        if ($data === null) {
            return null;
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ProfileException('"retention" must be null or an object such as {"keep": 14}.');
        }
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new ProfileException("Unknown field \"$key\" in \"retention\" (known: " . implode(', ', self::KEYS) . ').');
            }
        }
        $keep = self::count($data, 'keep', 1, 100000);
        $maxAgeDays = self::count($data, 'maxAgeDays', 1, 36500);
        $maxBytes = self::count($data, 'maxBytes', 1048576, null);

        return $keep === null && $maxAgeDays === null && $maxBytes === null ? null : new self($keep, $maxAgeDays, $maxBytes);
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return array_filter(['keep' => $this->keep, 'maxAgeDays' => $this->maxAgeDays, 'maxBytes' => $this->maxBytes], static fn (?int $value): bool => $value !== null);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function count(array $data, string $key, int $minimum, ?int $maximum): ?int
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value < $minimum || ($maximum !== null && $value > $maximum)) {
            throw new ProfileException("\"retention.$key\" must be a whole number from $minimum" . ($maximum === null ? ' up.' : " to $maximum."));
        }

        return $value;
    }
}
