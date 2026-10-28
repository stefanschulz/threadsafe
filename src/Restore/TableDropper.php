<?php

declare(strict_types=1);

namespace ThreadSafe\Restore;

use ThreadSafe\Database\Connection;
use ThreadSafe\Engine\Budget;

/**
 * Deletes the tables and views of the target database that are in the way of a restore (the option "delete the existing tables first"): all
 * whose names begin with the table prefix of the target, nothing else. It works in batches and stops at the budget, so that a database with
 * thousands of tables is cleared in several ticks; calling it again continues. The restore calls it only when the person asked for it.
 */
final class TableDropper
{
    private const int BATCH = 100;

    /**
     * The objects of the prefix, at most `$limit`, with their type (`BASE TABLE`, `VIEW`, `SEQUENCE` ...).
     *
     * @return list<array{name: string, type: string}>
     */
    public static function inTheWay(Connection $db, string $prefix, int $limit = self::BATCH): array
    {
        $rows = $db->select(
            'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ? ORDER BY TABLE_NAME LIMIT ' . max(1, $limit),
            [self::like($prefix)],
        );

        return array_map(static fn (array $row): array => ['name' => (string) $row['TABLE_NAME'], 'type' => (string) $row['TABLE_TYPE']], $rows);
    }

    /** How many tables and views of the prefix there are. */
    public static function count(Connection $db, string $prefix): int
    {
        return (int) $db->scalar('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?', [self::like($prefix)]);
    }

    /**
     * Drops what is in the way until it is gone or the budget is used up (at least one batch is dropped per call).
     *
     * @return int how many are left (0 = the database is clear of them)
     */
    public static function drop(Connection $db, string $prefix, Budget $budget): int
    {
        $db->execute('SET FOREIGN_KEY_CHECKS = 0');
        $dropped = 0;
        do {
            $batch = self::inTheWay($db, $prefix);
            foreach ($batch as $object) {
                $kind = match ($object['type']) {
                    'VIEW' => 'VIEW',
                    'SEQUENCE' => 'SEQUENCE',
                    default => 'TABLE',
                };
                $db->execute("DROP $kind IF EXISTS " . $db->quote($object['name']));
                ++$dropped;
            }
            $budget->note(sprintf('deleting the existing tables: %d deleted in this step', $dropped));
        } while ($batch !== [] && !$budget->exhausted());

        return self::count($db, $prefix);
    }

    /** The pattern for the names that begin with the prefix (`_` and `%` in the prefix mean themselves). */
    private static function like(string $prefix): string
    {
        return addcslashes($prefix, '%_\\') . '%';
    }

    private function __construct()
    {
    }
}
