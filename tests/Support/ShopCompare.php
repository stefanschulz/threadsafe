<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use PDO;

/**
 * Compares the database of a restored shop with the one it was restored from, table by table.
 */
final class ShopCompare
{
    /**
     * Tables whose content differs. A table in which a text column mentions the old domain is only compared by its number of rows
     * (the restore changed the domain in it); the others are compared exactly - by CHECKSUM TABLE if both servers are of the same
     * kind (the two kinds compute it differently), otherwise by the sum of the CRC32 of every row.
     *
     * @param list<string> $structureOnly tables (with prefix) whose rows the backup leaves out
     *
     * @return list<string> the tables that differ, with the reason
     */
    public static function differences(PDO $source, string $sourcePrefix, PDO $target, string $targetPrefix, string $oldHost, array $structureOnly): array
    {
        $sameKind = str_contains(self::value($source, 'SELECT VERSION()'), 'MariaDB') === str_contains(self::value($target, 'SELECT VERSION()'), 'MariaDB');
        $tables = $source->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY 1")?->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $differences = [];
        foreach ($tables as $table) {
            $table = (string) $table;
            $other = $targetPrefix . substr($table, strlen($sourcePrefix));
            if (in_array($table, $structureOnly, true) || in_array($table, ['ps_page', 'ps_page_type'], true)) {
                continue;   // no rows by design, or statistics of the pages a self-test visited
            }
            if (self::value($target, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$other'") !== '1') {
                $differences[] = "$other is missing";
                continue;
            }
            if (self::value($source, "SELECT COUNT(*) FROM `$table`") !== self::value($target, "SELECT COUNT(*) FROM `$other`")) {
                $differences[] = "$other has another number of rows";
                continue;
            }
            if (self::mentions($source, $table, $oldHost)) {
                continue;
            }
            if (self::fingerprint($source, $table, $sameKind) !== self::fingerprint($target, $other, $sameKind)) {
                $differences[] = "$other has other content";
            }
        }

        return $differences;
    }

    /** Whether a text column of the table mentions the host name (the new domain changes those tables). */
    public static function mentions(PDO $db, string $table, string $host): bool
    {
        $statement = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND DATA_TYPE IN ('char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json')");
        $columns = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_COLUMN);
        if ($columns === []) {
            return false;
        }
        $count = $db->prepare("SELECT COUNT(*) FROM `$table` WHERE " . implode(' OR ', array_map(static fn (mixed $c): string => '`' . $c . '` LIKE ?', $columns)));
        $count->execute(array_fill(0, count($columns), '%' . $host . '%'));

        return (int) $count->fetchColumn() > 0;
    }

    private static function fingerprint(PDO $db, string $table, bool $checksum): string
    {
        if ($checksum) {
            return (string) ($db->query("CHECKSUM TABLE `$table`")?->fetch(PDO::FETCH_NUM)[1] ?? '');
        }
        $statement = $db->query("SELECT * FROM `$table`");
        $sum = 0;
        $rows = 0;
        while ($statement !== false && ($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $sum = ($sum + crc32(serialize($row))) & 0xFFFFFFFF;
            ++$rows;
        }

        return $rows . ':' . $sum;
    }

    private static function value(PDO $db, string $sql): string
    {
        return (string) $db->query($sql)?->fetchColumn();
    }

    private function __construct()
    {
    }
}
