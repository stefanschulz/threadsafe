<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

/**
 * How the value of a column is written into a dump.
 */
enum ColumnKind
{
    /** Whole numbers: written as they are. */
    case Integer;

    /** FLOAT and DOUBLE: read as `(column + 0e0)`, written in PHP's shortest round-trip form. */
    case Float;

    /** Bytes: written as a hex literal. */
    case Binary;

    /** Everything else (text, dates, DECIMAL, JSON, ENUM, SET ...): a quoted string. */
    case Text;

    /**
     * @param string $type the DATA_TYPE of information_schema.COLUMNS
     */
    public static function of(string $type): self
    {
        return match (strtolower($type)) {
            'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'year' => self::Integer,
            'float', 'double', 'real' => self::Float,
            'bit', 'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob',
            'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection' => self::Binary,
            default => self::Text,
        };
    }
}
