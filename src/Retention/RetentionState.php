<?php

declare(strict_types=1);

namespace ThreadSafe\Retention;

/**
 * What is remembered about the retention of one profile (`data/retention/<profile>.json`): which rules the person has seen at work
 * ("confirmed", a hash of the rules), when retention ran last and what it did, and how many deletions wait for the person's go-ahead.
 * Losing the file means that the rules are asked about once more.
 */
final readonly class RetentionState
{
    public function __construct(
        public ?string $confirmed = null,
        public ?int $lastRun = null,
        public int $lastDeleted = 0,
        public int $lastFreed = 0,
        public ?int $waiting = null,
    ) {
    }

    /**
     * @return array{confirmed: string|null, lastRun: int|null, lastDeleted: int, lastFreed: int, waiting: int|null}
     */
    public function toArray(): array
    {
        return ['confirmed' => $this->confirmed, 'lastRun' => $this->lastRun, 'lastDeleted' => $this->lastDeleted, 'lastFreed' => $this->lastFreed, 'waiting' => $this->waiting];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $confirmed = is_string($data['confirmed'] ?? null) ? $data['confirmed'] : null;
        $lastRun = is_int($data['lastRun'] ?? null) ? $data['lastRun'] : null;
        $waiting = is_int($data['waiting'] ?? null) ? $data['waiting'] : null;

        return new self($confirmed, $lastRun, is_int($data['lastDeleted'] ?? null) ? $data['lastDeleted'] : 0, is_int($data['lastFreed'] ?? null) ? $data['lastFreed'] : 0, $waiting);
    }
}
