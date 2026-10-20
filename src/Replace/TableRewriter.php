<?php

declare(strict_types=1);

namespace ThreadSafe\Replace;

use ThreadSafe\Core\Journal;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\TableInfo;

/**
 * Replaces domains in all text columns of a table, in chunks of rows ordered by primary key.
 *
 * Only rows that mention one of the host names are read (a LIKE pre-filter in SQL), so scanning a big table costs
 * one index-free pass in the database but almost no PHP work. Every value is handed to the {@see ValueRewriter}, which
 * keeps serialized and JSON values intact. Primary key columns are never rewritten. A table without a primary key
 * cannot be updated row by row; it is only counted.
 *
 * With $apply = false nothing is written (dry run), with $apply = true each chunk is updated in one transaction and
 * every changed cell is journalled with its old and new value.
 */
final class TableRewriter
{
    private const int SAMPLE_CONTEXT = 50;

    public function __construct(
        private readonly Connection $db,
        private readonly UrlMatcher $matcher,
        private readonly ValueRewriter $rewriter,
        private readonly int $chunkSize = 2000,
    ) {
    }

    /**
     * @param string $rowFilter      extra SQL condition restricting the rows to look at (internal use, trusted), or ''
     * @param bool   $replaceBare    also replace bare domain names
     * @param bool   $apply          write the changes
     */
    public function rewrite(TableInfo $table, string $rowFilter, bool $replaceBare, bool $apply, Journal $journal): TableRewriteResult
    {
        $result = new TableRewriteResult($table->name);
        $columns = array_values(array_diff($table->textColumns, $table->primaryKey));
        if ($columns === []) {
            return $result;
        }

        [$candidateSql, $candidateParams] = $this->candidateCondition($columns, $rowFilter);

        if ($table->primaryKey === []) {
            $result->candidatesWithoutKey = (int) $this->db->scalar(
                'SELECT COUNT(*) FROM ' . $this->db->quote($table->name) . ' WHERE ' . $candidateSql,
                $candidateParams,
            );

            return $result;
        }

        $quotedKey = array_map($this->db->quote(...), $table->primaryKey);
        $select = implode(', ', array_map($this->db->quote(...), [...$table->primaryKey, ...$columns]));
        $order = implode(', ', $quotedKey);
        $keyTuple = '(' . implode(', ', $quotedKey) . ')';
        $keyMarks = '(' . implode(', ', array_fill(0, count($quotedKey), '?')) . ')';

        /** @var list<scalar|null>|null $last */
        $last = null;
        while (true) {
            $sql = "SELECT $select FROM " . $this->db->quote($table->name) . " WHERE ($candidateSql)"
                . ($last === null ? '' : " AND $keyTuple > $keyMarks")
                . " ORDER BY $order LIMIT " . $this->chunkSize;
            $rows = $this->db->select($sql, [...$candidateParams, ...($last ?? [])]);
            if ($rows === []) {
                break;
            }

            /** @var list<array{key: array<string, scalar|null>, set: array<string, string>, old: array<string, string>}> $updates */
            $updates = [];
            foreach ($rows as $row) {
                ++$result->rowsExamined;
                $key = [];
                foreach ($table->primaryKey as $keyColumn) {
                    /** @var scalar|null $keyValue */
                    $keyValue = $row[$keyColumn];
                    $key[$keyColumn] = $keyValue;
                }

                $set = [];
                $old = [];
                foreach ($columns as $column) {
                    $value = $row[$column];
                    if (!is_string($value) || !$this->matcher->mayContain($value)) {
                        continue;
                    }
                    $outcome = $this->rewriter->rewrite($value, $replaceBare);
                    $columnResult = $result->column($column);
                    $columnResult->stats->add($outcome->stats);
                    if ($outcome->changed) {
                        ++$columnResult->rowsChanged;
                        $columnResult->sampleBefore ??= $this->excerpt($value);
                        $columnResult->sampleAfter ??= $this->excerpt($outcome->value);
                        $set[$column] = $outcome->value;
                        $old[$column] = $value;
                    }
                }
                if ($set !== []) {
                    ++$result->rowsChanged;
                    $updates[] = ['key' => $key, 'set' => $set, 'old' => $old];
                }
                $last = array_values($key);
            }

            if ($apply && $updates !== []) {
                $this->db->transaction(function () use ($table, $updates, $journal): void {
                    foreach ($updates as $update) {
                        $this->update($table, $update['key'], $update['set']);
                        foreach ($update['set'] as $column => $new) {
                            $journal->database($table->name, $update['key'], $column, $update['old'][$column], $new);
                        }
                    }
                });
            }
        }

        // Drop columns in which only look-alikes (bare names, e-mail addresses) were seen? No: they are reported.
        return $result;
    }

    /**
     * @param list<string> $columns
     *
     * @return array{string, list<string>} SQL condition and its parameters
     */
    private function candidateCondition(array $columns, string $rowFilter): array
    {
        $likes = [];
        $parameters = [];
        foreach ($columns as $column) {
            foreach ($this->matcher->hosts() as $host) {
                $likes[] = $this->db->quote($column) . ' LIKE ?';
                $parameters[] = '%' . addcslashes($host, '%_\\') . '%';
            }
        }
        $sql = '(' . implode(' OR ', $likes) . ')' . ($rowFilter === '' ? '' : " AND ($rowFilter)");

        return [$sql, $parameters];
    }

    /**
     * @param array<string, scalar|null> $key
     * @param array<string, string>      $set
     */
    private function update(TableInfo $table, array $key, array $set): void
    {
        $assignments = implode(', ', array_map(fn (string $c): string => $this->db->quote($c) . ' = ?', array_keys($set)));
        $conditions = implode(' AND ', array_map(fn (string $c): string => $this->db->quote($c) . ' = ?', array_keys($key)));
        $this->db->execute(
            'UPDATE ' . $this->db->quote($table->name) . " SET $assignments WHERE $conditions",
            [...array_values($set), ...array_values($key)],
        );
    }

    /** A single-line excerpt around the first host name in the value. */
    private function excerpt(string $value): string
    {
        $position = false;
        foreach ($this->matcher->hosts() as $host) {
            $found = stripos($value, $host);
            if ($found !== false && ($position === false || $found < $position)) {
                $position = $found;
            }
        }
        $start = $position === false ? 0 : max(0, $position - self::SAMPLE_CONTEXT);
        $excerpt = substr($value, $start, 2 * self::SAMPLE_CONTEXT + 20);

        return ($start > 0 ? '…' : '') . str_replace(["\r", "\n", "\0"], ['\r', '\n', '\0'], $excerpt)
            . ($start + strlen($excerpt) < strlen($value) ? '…' : '');
    }
}
