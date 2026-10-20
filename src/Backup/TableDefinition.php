<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

/**
 * What the dumper needs to know about a table: the statement that creates it, the columns to write (generated columns are
 * recomputed by the server on import and left out) and the primary key.
 */
final readonly class TableDefinition
{
    /**
     * @param list<Column> $columns    columns in table order, without generated ones
     * @param list<Column> $primaryKey the columns of the primary key; empty if the table has none or the key cannot be
     *                                 used to read the table in pages (see {@see SchemaReader})
     */
    public function __construct(
        public string $name,
        public string $create,
        public array $columns,
        public array $primaryKey,
    ) {
    }

    /** The table can be read in pages ordered by its primary key. */
    public function isKeyed(): bool
    {
        return $this->primaryKey !== [];
    }

    public function hasLargeColumns(): bool
    {
        foreach ($this->columns as $column) {
            if ($column->large) {
                return true;
            }
        }

        return false;
    }
}
