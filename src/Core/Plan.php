<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

/**
 * What a step intends to do, computed without changing anything: the changes and some notices.
 *
 * A plan without changes means "already correct": the runner then does not call apply().
 */
final class Plan
{
    /** @var list<Change> */
    private array $changes = [];

    /** @var list<Notice> */
    private array $notices = [];

    public function add(Change $change): self
    {
        $this->changes[] = $change;

        return $this;
    }

    public function notice(Notice $notice): self
    {
        $this->notices[] = $notice;

        return $this;
    }

    /** @return list<Change> */
    public function changes(): array
    {
        return $this->changes;
    }

    /** @return list<Notice> */
    public function notices(): array
    {
        return $this->notices;
    }

    public function hasChanges(): bool
    {
        return $this->changes !== [];
    }

    /** Number of individual modifications (a change may stand for many). */
    public function changeCount(): int
    {
        return array_sum(array_map(static fn (Change $c): int => $c->count, $this->changes));
    }

    /**
     * The inverse of {@see toArray()}.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $plan = new self();
        foreach ((array) ($data['changes'] ?? []) as $change) {
            $plan->add(Change::fromArray((array) $change));
        }
        foreach ((array) ($data['notices'] ?? []) as $notice) {
            $plan->notice(Notice::fromArray((array) $notice));
        }

        return $plan;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'changes' => array_map(static fn (Change $c): array => $c->toArray(), $this->changes),
            'notices' => array_map(static fn (Notice $n): array => $n->toArray(), $this->notices),
        ];
    }
}
