<?php

declare(strict_types=1);

namespace ThreadSafe\Database;

/**
 * What the rewriting steps need to know about one table.
 */
final readonly class TableInfo
{
    /**
     * @param list<string> $textColumns character columns (char, varchar, text types; JSON is a text type in MariaDB)
     * @param list<string> $primaryKey  primary key columns in key order, empty if the table has no primary key
     * @param int          $rowEstimate approximate number of rows (from the storage engine statistics)
     * @param array<string, array{string, int}> $limits what a column holds at most: `['chars', n]` for char and varchar, `['bytes', n]` for the text types (by column name; a column that is not listed has no known limit)
     */
    public function __construct(
        public string $name,
        public array $textColumns,
        public array $primaryKey,
        public int $rowEstimate,
        public array $limits = [],
    ) {
    }

    /** Whether $value is too long for the column (false if the limit is not known). */
    public function tooLong(string $column, string $value): bool
    {
        [$unit, $max] = $this->limits[$column] ?? ['', 0];
        if ($unit === 'chars') {
            return mb_strlen($value, 'UTF-8') > $max;
        }

        return $unit === 'bytes' && strlen($value) > $max;
    }
}
