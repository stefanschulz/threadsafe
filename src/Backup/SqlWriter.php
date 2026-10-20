<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

use InvalidArgumentException;

/**
 * The text of a dump: values, statements and file headers. Pure functions - what is written does not depend on the
 * database connection, so a dump imports with any client and the formatting is tested without a database.
 *
 * Strings are written in single quotes with the escapes that every MariaDB and MySQL accepts (`\\`, `\'`, `\0`, `\n`, `\r`,
 * `\Z`); the header of each file sets a `sql_mode` without NO_BACKSLASH_ESCAPES.
 */
final class SqlWriter
{
    /** Version of the layout of the dump files, recorded in every file header. */
    public const int FORMAT = 1;

    /** A string literal. */
    public static function quote(string $value): string
    {
        return "'" . strtr($value, ['\\' => '\\\\', "'" => "\\'", "\0" => '\0', "\n" => '\n', "\r" => '\r', "\x1a" => '\Z']) . "'";
    }

    /** A name in backticks. */
    public static function identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /** Bytes as a hex literal; the empty string as `''`, because `0x` alone is not valid. */
    public static function hex(string $bytes): string
    {
        return $bytes === '' ? "''" : '0x' . strtoupper(bin2hex($bytes));
    }

    /**
     * A value as it is written into a statement.
     *
     * @param mixed $value  what the query returned for the column
     * @param bool  $isHex  for a binary column: the value is hex digits already (`HEX()` in the query), not the bytes
     */
    public static function literal(mixed $value, Column $column, bool $isHex = false): string
    {
        if ($value === null) {
            return 'NULL';
        }

        switch ($column->kind) {
            case ColumnKind::Integer:
                if (is_int($value) || (is_string($value) && preg_match('/^-?\d{1,20}$/', $value) === 1)) {
                    return (string) $value;
                }
                break;
            case ColumnKind::Float:
                if (is_float($value)) {
                    if (!is_finite($value)) {
                        throw new InvalidArgumentException('A floating point value is not finite.');
                    }

                    return var_export($value, true);
                }
                if (is_int($value)) {
                    return (string) $value;
                }
                break;
            case ColumnKind::Binary:
                if (is_string($value)) {
                    return $isHex ? ($value === '' ? "''" : '0x' . $value) : self::hex($value);
                }
                break;
            case ColumnKind::Text:
                break;
        }

        return self::quote(is_scalar($value) ? (string) $value : '');
    }

    /** The first lines of every dump file. */
    public static function header(string $what): string
    {
        return '-- ThreadSafe dump, ' . str_replace(["\r", "\n"], '?', $what) . ', format ' . self::FORMAT . "\n"
            . "SET NAMES utf8mb4;\n"
            . "SET time_zone = '+00:00';\n"
            . "SET FOREIGN_KEY_CHECKS = 0;\n"
            . "SET UNIQUE_CHECKS = 0;\n"
            . "SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n";
    }

    /** What a table's file starts with: header, DROP and CREATE. */
    public static function tableStart(string $table, string $create): string
    {
        return self::header('table ' . self::identifier($table))
            . 'DROP TABLE IF EXISTS ' . self::identifier($table) . ";\n"
            . $create . ";\n";
    }

    /**
     * The beginning of an INSERT statement for a table, up to and including `VALUES` and the line break.
     *
     * @param list<Column> $columns
     */
    public static function insertHead(string $table, array $columns): string
    {
        return 'INSERT INTO ' . self::identifier($table) . ' (' . implode(', ', array_map(static fn (Column $c): string => self::identifier($c->name), $columns)) . ") VALUES\n";
    }

    /**
     * `UPDATE` that appends a piece to a cell.
     *
     * @param list<Column> $keyColumns
     * @param list<string> $keyLiterals the key of the row, written as literals, in the order of the key columns
     * @param list<Column> $untouched  columns that would change by themselves with the row (`ON UPDATE CURRENT_TIMESTAMP`): set to their own value
     */
    public static function appendPiece(string $table, Column $column, string $literal, array $keyColumns, array $keyLiterals, array $untouched): string
    {
        $set = [self::identifier($column->name) . ' = CONCAT(' . self::identifier($column->name) . ', ' . $literal . ')'];
        foreach ($untouched as $other) {
            $set[] = self::identifier($other->name) . ' = ' . self::identifier($other->name);
        }
        $where = [];
        foreach ($keyColumns as $i => $key) {
            $where[] = self::identifier($key->name) . ' = ' . $keyLiterals[$i];
        }

        return 'UPDATE ' . self::identifier($table) . ' SET ' . implode(', ', $set) . ' WHERE ' . implode(' AND ', $where) . ";\n";
    }

    /**
     * The condition that selects the rows after a key in key order: `a > ?`, or `a > ? OR (a = ? AND b > ?)` for several
     * columns (the form every optimizer can turn into an index range, unlike `(a, b) > (?, ?)` on MariaDB).
     *
     * @param list<Column> $keyColumns
     *
     * @return array{string, int} the condition and the number of parameters it takes
     */
    public static function afterKey(array $keyColumns): array
    {
        $alternatives = [];
        $count = 0;
        foreach ($keyColumns as $i => $column) {
            $terms = [];
            for ($j = 0; $j < $i; ++$j) {
                $terms[] = self::identifier($keyColumns[$j]->name) . ' = ?';
                ++$count;
            }
            $terms[] = self::identifier($column->name) . ' > ?';
            ++$count;
            $alternatives[] = count($terms) === 1 ? $terms[0] : '(' . implode(' AND ', $terms) . ')';
        }

        return [implode(' OR ', $alternatives), $count];
    }

    /**
     * A file name for a table inside the archive: `0007_product.sql`. The number keeps the dump order and makes the name
     * unique; characters other than letters, digits, dot, hyphen and underscore become underscores.
     */
    public static function tableFileName(int $number, string $table): string
    {
        // one underscore per character; a name that is not valid UTF-8 gets one per byte
        $name = preg_replace('/[^A-Za-z0-9_.-]/u', '_', $table) ?? preg_replace('/[^A-Za-z0-9_.-]/', '_', $table) ?? '_';
        $name = ltrim($name, '.');

        return sprintf('%04d_%s.sql', $number, substr($name === '' ? '_' : $name, 0, 100));
    }

    private function __construct()
    {
    }
}
