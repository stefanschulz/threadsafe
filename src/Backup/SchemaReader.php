<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

use ThreadSafe\Database\Connection;
use ThreadSafe\Database\DatabaseError;

/**
 * Reads from the database what a dump needs: the tables with their columns, keys and CREATE statements, the views, the
 * triggers, and the objects that are not dumped (routines, events, sequences).
 */
final class SchemaReader
{
    /** Column types that a primary key may have and still be used to read in pages. */
    private const array KEY_TYPES = [
        'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'year', 'decimal', 'numeric',
        'char', 'varchar', 'binary', 'varbinary', 'tinyblob', 'blob', 'date', 'datetime', 'timestamp', 'time',
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Names of the tables to dump, in the order of the dump (by name). `SYSTEM VERSIONED` tables of MariaDB are dumped like
     * the others (their history is not part of the dump).
     *
     * @return list<string>
     */
    public function tables(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['n'],
            $this->db->select(
                "SELECT TABLE_NAME AS n FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE IN ('BASE TABLE', 'SYSTEM VERSIONED') ORDER BY TABLE_NAME",
            ),
        );
    }

    /**
     * @throws DatabaseError
     */
    public function table(string $name): TableDefinition
    {
        $create = (string) ($this->db->select('SHOW CREATE TABLE ' . $this->db->quote($name))[0]['Create Table'] ?? '');
        if ($create === '') {
            throw new DatabaseError("The server did not return the structure of table \"$name\".");
        }

        $columns = [];
        foreach ($this->db->select(
            'SELECT COLUMN_NAME AS n, DATA_TYPE AS t, EXTRA AS e, GENERATION_EXPRESSION AS g FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$name],
        ) as $row) {
            $extra = strtolower((string) $row['e']);
            // Not "DEFAULT_GENERATED": that is a column with an expression as its default, which is an ordinary column.
            if (trim((string) ($row['g'] ?? '')) !== '' || preg_match('/\b(virtual|stored|persistent) generated\b|^(virtual|stored|persistent)\b/', $extra) === 1) {
                continue;
            }
            $column = (string) $row['n'];
            $columns[$column] = new Column(
                $column,
                (string) $row['t'],
                preg_match('/json_valid\(\s*`' . preg_quote(str_replace('`', '``', $column), '/') . '`\s*\)/i', $create) !== 1,
                str_contains($extra, 'on update'),
            );
        }

        return new TableDefinition($name, $create, array_values($columns), $this->primaryKey($name, $columns));
    }

    /**
     * Views in an order in which they can be created: a view comes after the views it uses.
     *
     * @return list<array{name: string, create: string}>
     */
    public function views(): array
    {
        $names = array_map(
            static fn (array $row): string => (string) $row['n'],
            $this->db->select("SELECT TABLE_NAME AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'VIEW' ORDER BY TABLE_NAME"),
        );
        $creates = [];
        foreach ($names as $name) {
            $creates[$name] = (string) ($this->db->select('SHOW CREATE VIEW ' . $this->db->quote($name))[0]['Create View'] ?? '');
        }

        $ordered = [];
        $visiting = [];
        $visit = function (string $name) use (&$visit, &$ordered, &$visiting, $creates, $names): void {
            if (isset($ordered[$name]) || isset($visiting[$name])) {
                return;
            }
            $visiting[$name] = true;
            foreach ($names as $other) {
                if ($other !== $name && str_contains($creates[$name], $this->db->quote($other))) {
                    $visit($other);
                }
            }
            $ordered[$name] = $creates[$name];
        };
        foreach ($names as $name) {
            $visit($name);
        }

        $result = [];
        foreach ($ordered as $name => $create) {
            $result[] = ['name' => $name, 'create' => $create];
        }

        return $result;
    }

    /**
     * Triggers in the order the server runs them: per table, timing and event, then by position.
     *
     * @return list<array{name: string, sqlMode: string, create: string}>
     */
    public function triggers(): array
    {
        $result = [];
        foreach ($this->db->select(
            'SELECT TRIGGER_NAME AS n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()
             ORDER BY EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_ORDER, TRIGGER_NAME',
        ) as $row) {
            $name = (string) $row['n'];
            $shown = $this->db->select('SHOW CREATE TRIGGER ' . $this->db->quote($name))[0] ?? [];
            $create = (string) ($shown['SQL Original Statement'] ?? '');
            if ($create === '') {
                throw new DatabaseError("The server did not return the definition of trigger \"$name\" (missing TRIGGER privilege?).");
            }
            $result[] = ['name' => $name, 'sqlMode' => (string) ($shown['sql_mode'] ?? ''), 'create' => $create];
        }

        return $result;
    }

    /**
     * What the database holds that a dump does not contain.
     *
     * @return list<array{type: string, name: string}>
     */
    public function unsupported(): array
    {
        $found = [];
        foreach ($this->db->select('SELECT ROUTINE_TYPE AS t, ROUTINE_NAME AS n FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() ORDER BY ROUTINE_NAME') as $row) {
            $found[] = ['type' => strtolower((string) $row['t']), 'name' => (string) $row['n']];
        }
        try {
            foreach ($this->db->select('SELECT EVENT_NAME AS n FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE() ORDER BY EVENT_NAME') as $row) {
                $found[] = ['type' => 'event', 'name' => (string) $row['n']];
            }
        } catch (DatabaseError) {
            // no privilege or no event scheduler tables: nothing to report
        }
        foreach ($this->db->select("SELECT TABLE_NAME AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'SEQUENCE' ORDER BY TABLE_NAME") as $row) {
            $found[] = ['type' => 'sequence', 'name' => (string) $row['n']];
        }

        return $found;
    }

    /**
     * The primary key as columns, or nothing if there is none or it cannot order a read in pages: a key with a prefix
     * (`name(10)`) orders by the whole value in the table but by the prefix in the index, a key with a column that the dump
     * does not write (generated) or one of a kind whose values cannot be compared safely.
     *
     * @param array<string, Column> $columns the columns that are dumped, by name
     *
     * @return list<Column>
     */
    private function primaryKey(string $table, array $columns): array
    {
        $key = [];
        foreach ($this->db->select(
            "SELECT COLUMN_NAME AS n, SUB_PART AS s FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'PRIMARY' ORDER BY SEQ_IN_INDEX",
            [$table],
        ) as $row) {
            $column = $columns[(string) $row['n']] ?? null;
            if ($column === null || $row['s'] !== null || !$this->usableInKey($column)) {
                return [];
            }
            $key[] = $column;
        }

        return $key;
    }

    private function usableInKey(Column $column): bool
    {
        // ENUM and SET sort by their number but compare as strings; BIT and geometry values are not compared as strings
        return in_array(strtolower($column->type), self::KEY_TYPES, true);
    }
}
