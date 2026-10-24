<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use PDO;
use PHPUnit\Framework\Assert;

/**
 * What the integration tests compare when a database was backed up and restored: one value per table that is equal for equal tables,
 * and whether a text column mentions a host (a restore that changes the domain changes those tables, which are then compared by their
 * number of rows and checked cell by cell by the tests of the replacement).
 */
final class TableCompare
{
    /** The first (or `$column`-th) column of the first row of a query. */
    public static function cell(PDO $db, string $sql, int $column = 0): string
    {
        $statement = $db->query($sql);
        Assert::assertNotFalse($statement);
        $row = $statement->fetch(PDO::FETCH_NUM);

        return is_array($row) ? (string) $row[$column] : '';
    }

    /** True if both servers are of the same kind (MariaDB or MySQL): only then is CHECKSUM TABLE comparable. */
    public static function sameKind(PDO $a, PDO $b): bool
    {
        return str_contains(self::cell($a, 'SELECT VERSION()'), 'MariaDB') === str_contains(self::cell($b, 'SELECT VERSION()'), 'MariaDB');
    }

    /** CHECKSUM TABLE, or the number of rows and the sum of the CRC32 of every row (independent of the order). */
    public static function fingerprint(PDO $db, string $table, bool $checksum): string
    {
        if ($checksum) {
            return self::cell($db, "CHECKSUM TABLE `$table`", 1);
        }
        $statement = $db->query("SELECT * FROM `$table`");
        Assert::assertNotFalse($statement);
        $sum = 0;
        $rows = 0;
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $sum = ($sum + crc32(serialize($row))) & 0xFFFFFFFF;
            ++$rows;
        }

        return $rows . ':' . $sum;
    }

    /** Whether a text column of the table mentions the host name. */
    public static function mentions(PDO $db, string $table, string $host): bool
    {
        $statement = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND DATA_TYPE IN ('char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext')");
        Assert::assertNotFalse($statement);
        $columns = $statement->fetchAll(PDO::FETCH_COLUMN);
        if ($columns === []) {
            return false;
        }
        $conditions = implode(' OR ', array_map(static fn (string $c): string => "`$c` LIKE ?", $columns));
        $count = $db->prepare("SELECT COUNT(*) FROM `$table` WHERE $conditions");
        $count->execute(array_fill(0, count($columns), '%' . $host . '%'));

        return (int) $count->fetchColumn() > 0;
    }

    /**
     * The tables of the database, without the views.
     *
     * @return list<string>
     */
    public static function tables(PDO $db): array
    {
        $listing = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY 1");
        Assert::assertNotFalse($listing);

        return array_values(array_map('strval', $listing->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function __construct()
    {
    }
}
