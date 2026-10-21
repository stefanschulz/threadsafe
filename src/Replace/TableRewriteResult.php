<?php

declare(strict_types=1);

namespace ThreadSafe\Replace;

/**
 * Result of rewriting one table (also of a dry run, which changes nothing).
 */
final class TableRewriteResult
{
    /** @var array<string, ColumnResult> only columns in which something was found */
    public array $columns = [];

    /** Rows that contain one of the host names in some text column (the candidates). */
    public int $rowsExamined = 0;

    /** Rows with at least one changed value. */
    public int $rowsChanged = 0;

    /** Set instead of rewriting when the table has no primary key: the number of candidate rows left untouched. */
    public ?int $candidatesWithoutKey = null;

    /**
     * Set when the rewriting stopped because the budget was used up, before the table was done: the primary key of the last row
     * looked at; the next call continues after it. Null: the table is finished.
     *
     * @var list<scalar|null>|null
     */
    public ?array $stoppedAfter = null;

    public function __construct(public readonly string $table)
    {
    }

    /** Adds the result for a later part of the same table. */
    public function merge(self $other): void
    {
        $this->rowsExamined += $other->rowsExamined;
        $this->rowsChanged += $other->rowsChanged;
        $this->candidatesWithoutKey = $other->candidatesWithoutKey ?? $this->candidatesWithoutKey;
        foreach ($other->columns as $name => $column) {
            $this->column($name)->merge($column);
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'examined' => $this->rowsExamined,
            'changed' => $this->rowsChanged,
            'withoutKey' => $this->candidatesWithoutKey,
            'columns' => array_map(static fn (ColumnResult $c): array => $c->toArray(), $this->columns),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $result = new self((string) $data['table']);
        $result->rowsExamined = (int) $data['examined'];
        $result->rowsChanged = (int) $data['changed'];
        $result->candidatesWithoutKey = isset($data['withoutKey']) ? (int) $data['withoutKey'] : null;
        foreach ((array) $data['columns'] as $name => $column) {
            $result->columns[(string) $name] = ColumnResult::fromArray((array) $column);
        }

        return $result;
    }

    public function column(string $name): ColumnResult
    {
        return $this->columns[$name] ??= new ColumnResult();
    }

    /** Sum of the statistics of all columns. */
    public function stats(): Stats
    {
        $total = new Stats();
        foreach ($this->columns as $column) {
            $total->add($column->stats);
        }

        return $total;
    }
}
