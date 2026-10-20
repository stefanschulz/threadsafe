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

    public function __construct(public readonly string $table)
    {
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
