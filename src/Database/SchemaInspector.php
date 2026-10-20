<?php

declare(strict_types=1);

namespace ThreadSafe\Database;

/**
 * Reads the structure of the current database (all base tables, their text columns and primary keys) with three
 * queries, so that inspecting hundreds of tables stays fast.
 */
final class SchemaInspector
{
    private const array TEXT_TYPES = ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array<string, TableInfo> keyed by table name, in name order
     */
    public function tables(): array
    {
        $tables = [];
        foreach ($this->db->select(
            "SELECT TABLE_NAME AS t, IFNULL(TABLE_ROWS, 0) AS r FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME",
        ) as $row) {
            $tables[(string) $row['t']] = ['rows' => (int) $row['r'], 'text' => [], 'pk' => []];
        }

        $marks = implode(',', array_fill(0, count(self::TEXT_TYPES), '?'));
        foreach ($this->db->select(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE IN ($marks) ORDER BY TABLE_NAME, ORDINAL_POSITION",
            self::TEXT_TYPES,
        ) as $row) {
            if (isset($tables[(string) $row['t']])) {
                $tables[(string) $row['t']]['text'][] = (string) $row['c'];
            }
        }

        foreach ($this->db->select(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY TABLE_NAME, ORDINAL_POSITION",
        ) as $row) {
            if (isset($tables[(string) $row['t']])) {
                $tables[(string) $row['t']]['pk'][] = (string) $row['c'];
            }
        }

        $result = [];
        foreach ($tables as $name => $info) {
            $result[$name] = new TableInfo($name, $info['text'], $info['pk'], $info['rows']);
        }

        return $result;
    }
}
