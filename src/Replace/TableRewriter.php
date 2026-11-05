<?php

declare(strict_types=1);

namespace ThreadSafe\Replace;

use ThreadSafe\Core\Journal;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\TableInfo;
use ThreadSafe\Engine\Budget;

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

    /** @var array<string, ValueRewriter> */
    private array $formRewriters = [];

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
     * @param array<string, ColumnTreatment> $treatments what is known about single columns (by column name): the format they hold, bare host names
     * @param Budget|null                    $budget     when given, the rewriting stops after a chunk once the budget is used up (at least one chunk is done);
     *                                                   the result then has {@see TableRewriteResult::$stoppedAfter}
     * @param list<scalar|null>|null         $after      continue after the row with this primary key (the $stoppedAfter of an earlier call)
     * @param list<string>                   $skipColumns names of columns of this table that are never rewritten (a WordPress `guid`)
     */
    public function rewrite(TableInfo $table, string $rowFilter, bool $replaceBare, bool $apply, Journal $journal, array $treatments = [], ?Budget $budget = null, ?array $after = null, array $skipColumns = []): TableRewriteResult
    {
        $result = new TableRewriteResult($table->name);
        $columns = array_values(array_diff($table->textColumns, $table->primaryKey, $skipColumns));
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
        $last = $after;
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
                    $treatment = $treatments[$column] ?? null;
                    $outcome = $this->rewriterFor($treatment?->form)->rewrite($value, $replaceBare || ($treatment->bare ?? false));
                    $columnResult = $result->column($column);
                    $columnResult->stats->add($outcome->stats);
                    if ($outcome->changed && $table->tooLong($column, $outcome->value)) {
                        // the new domain is longer than the old one and the value was close to the limit of its column: an UPDATE would fail (error 1406) and
                        // stop the whole run, so this one value keeps the old domain and the report says so
                        ++$columnResult->tooLong;
                    } elseif ($outcome->changed) {
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

            if ($budget !== null && count($rows) >= $this->chunkSize && $budget->exhausted()) {
                $result->stoppedAfter = $last;

                return $result;
            }
        }

        // Drop columns in which only look-alikes (bare names, e-mail addresses) were seen? No: they are reported.
        return $result;
    }

    /** The value rewriter for a column of a known format; null = the one that detects the format. */
    private function rewriterFor(?string $form): ValueRewriter
    {
        if ($form === null) {
            return $this->rewriter;
        }

        return $this->formRewriters[$form] ??= new ValueRewriter($this->matcher, match ($form) {
            'serialized' => [new SerializedCodec()],
            'json' => [new JsonCodec()],
            default => [],
        });
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
