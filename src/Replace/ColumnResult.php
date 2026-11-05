<?php

declare(strict_types=1);

namespace ThreadSafe\Replace;

/**
 * What the rewriting found and changed in one column.
 */
final class ColumnResult
{
    public int $rowsChanged = 0;

    /** Values that were left as they were because the new value would not fit into the column. */
    public int $tooLong = 0;

    public readonly Stats $stats;

    /** A short excerpt of the first changed value, before and after. */
    public ?string $sampleBefore = null;

    public ?string $sampleAfter = null;

    public function __construct()
    {
        $this->stats = new Stats();
    }

    /** Adds what was found in a later part of the same column; the first samples are kept. */
    public function merge(self $other): void
    {
        $this->rowsChanged += $other->rowsChanged;
        $this->tooLong += $other->tooLong;
        $this->stats->add($other->stats);
        $this->sampleBefore ??= $other->sampleBefore;
        $this->sampleAfter ??= $other->sampleAfter;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['rowsChanged' => $this->rowsChanged, 'tooLong' => $this->tooLong, 'stats' => $this->stats->toArray(), 'before' => $this->sampleBefore, 'after' => $this->sampleAfter];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $column = new self();
        $column->rowsChanged = (int) ($data['rowsChanged'] ?? 0);
        $column->tooLong = (int) ($data['tooLong'] ?? 0);
        $column->stats->add(Stats::fromArray((array) ($data['stats'] ?? [])));
        $column->sampleBefore = isset($data['before']) ? (string) $data['before'] : null;
        $column->sampleAfter = isset($data['after']) ? (string) $data['after'] : null;

        return $column;
    }
}
