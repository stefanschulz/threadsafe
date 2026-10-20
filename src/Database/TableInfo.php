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
     */
    public function __construct(
        public string $name,
        public array $textColumns,
        public array $primaryKey,
        public int $rowEstimate,
    ) {
    }
}
