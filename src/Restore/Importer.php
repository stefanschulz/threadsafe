<?php

declare(strict_types=1);

namespace ThreadSafe\Restore;

use RuntimeException;
use ThreadSafe\Backup\SqlWriter;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\DatabaseError;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\TickOutcome;

/**
 * Imports the SQL files of a backup (`db/0001_<table>.sql` ... then `_views.sql`, `_triggers.sql`) into the database of the
 * connection, in ticks, without the `mysql` client: the statements are cut out of the files by {@see SqlSplitter} and run one
 * by one. Optionally the table prefix is changed on the way ({@see PrefixRewriter}).
 *
 * The state is the index of the file and the byte offset (and delimiter) after the last statement run. A tick begins with
 * the session settings every dump file starts with, because it may continue in the middle of a file.
 *
 * Crash safety: a statement that ran but whose tick did not save its state would run a second time on resume and fail on a
 * duplicate key. So a tick leaves a marker file while it works and removes it when it ends in order; a marker found at the
 * start of a tick means the previous tick died, and the file in progress starts again from its beginning (it begins with
 * `DROP TABLE IF EXISTS`, so that is safe). Same after an error.
 *
 * State: `directory`, `files` (names), `sizes`, `file` (index of the file in progress), `offset`, `delimiter`, `statements`,
 * `restarts`, `prefixFrom`, `prefixTo` (null: no change), `firstTable` and `lastTable` (the first and the most recent table created, or
 * null: a resumed import looks for them, see {@see tick()}), `bytes` and `total` (for progress).
 */
final class Importer
{
    public const string MARKER = '.import-inflight';

    /** Warnings of the server that mean a value was changed or lost: packet overflow, truncation, out of range, incorrect value, too long, NULL for NOT NULL. */
    private const array DATA_LOSS_CODES = [1301, 1265, 1292, 1264, 1366, 1406, 1263];

    /** @var array<string, bool> table and column => whether the column is an ENUM (a cache of {@see isEmptyEnumValue()}) */
    private array $enumColumns = [];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array<string, mixed> $parameters `directory` with the SQL files; `prefixFrom` and `prefixTo` (both or none) to
     *                                         change the table prefix
     *
     * @return array<string, mixed>
     *
     * @throws ImportException no SQL files, or the prefix cannot be changed in this dump
     */
    public function initialState(array $parameters): array
    {
        $directory = rtrim((string) ($parameters['directory'] ?? ''), '/\\');
        $files = self::filesIn($directory);
        if ($files === []) {
            throw new ImportException("There are no SQL files to import in \"$directory\".");
        }
        $from = isset($parameters['prefixFrom']) ? (string) $parameters['prefixFrom'] : null;
        $to = isset($parameters['prefixTo']) ? (string) $parameters['prefixTo'] : null;
        if ($from === null || $to === null || $from === $to) {
            $from = $to = null;
        } else {
            $obstacles = self::prefixObstacles($directory, $files);
            if ($obstacles !== []) {
                throw new ImportException('The table prefix cannot be changed: ' . implode('; ', $obstacles) . '.');
            }
        }
        $sizes = array_map(static fn (string $f): int => (int) filesize($directory . '/' . $f), $files);

        return [
            'directory' => $directory,
            'files' => $files,
            'sizes' => $sizes,
            'file' => 0,
            'offset' => 0,
            'delimiter' => ';',
            'statements' => 0,
            'restarts' => 0,
            'firstTable' => null,
            'lastTable' => null,
            'prefixFrom' => $from,
            'prefixTo' => $to,
            'bytes' => 0,
            'total' => array_sum($sizes),
        ];
    }

    /**
     * The SQL files of a backup directory in import order: tables in dump order, then views, then triggers.
     *
     * @return list<string> file names
     */
    public static function filesIn(string $directory): array
    {
        $names = scandir($directory) ?: [];
        $tables = array_values(array_filter($names, static fn (string $name): bool => preg_match('/^\d{4,}_.*\.sql$/', $name) === 1));
        sort($tables, SORT_STRING);

        return array_merge($tables, array_values(array_intersect(['_views.sql', '_triggers.sql'], $names)));
    }

    /**
     * What stands in the way of a prefix change in these files: views and triggers, foreign keys.
     *
     * @param list<string> $files names in $directory
     *
     * @return list<string>
     */
    public static function prefixObstacles(string $directory, array $files): array
    {
        $reasons = [];
        foreach ($files as $name) {
            if ($name === '_views.sql' || $name === '_triggers.sql') {
                $reasons['objects'] = 'the dump contains views or triggers, which name tables';

                continue;
            }
            // the CREATE TABLE is among the first statements of a table's file
            $splitter = new SqlSplitter($directory . '/' . $name, 0, ';', 16384);
            try {
                for ($i = 0; $i < 6 && ($statement = $splitter->next()) !== null; ++$i) {
                    $reason = PrefixRewriter::obstacle($statement->text);
                    if ($reason !== null) {
                        $reasons[$reason] = $reason;
                    }
                    if (str_starts_with(ltrim($statement->text), 'CREATE')) {
                        break;
                    }
                }
            } finally {
                $splitter->close();
            }
        }

        return array_values($reasons);
    }

    /**
     * @param array<string, mixed> $state
     *
     * @throws ImportException a statement failed
     */
    public function tick(array $state, Budget $budget): TickOutcome
    {
        $directory = (string) $state['directory'];
        /** @var list<string> $files */
        $files = $state['files'];
        /** @var list<int> $sizes */
        $sizes = $state['sizes'];
        $file = (int) $state['file'];
        $offset = (int) $state['offset'];
        $delimiter = (string) $state['delimiter'];
        $statements = (int) $state['statements'];
        $statementsAtStart = $statements;
        $restarts = (int) $state['restarts'];
        $done = array_sum(array_slice($sizes, 0, $file));
        $rewriter = $state['prefixFrom'] === null ? null : new PrefixRewriter((string) $state['prefixFrom'], (string) $state['prefixTo']);

        $firstTable = $state['firstTable'] ?? null;
        $lastTable = $state['lastTable'] ?? null;
        if ($file > 0 && $file < count($files) && !$this->tablesExist($firstTable, $lastTable)) {
            // The tables made so far are not there: the database was emptied or replaced while the restore waited (the person fixed the
            // cause of a failure by starting with a fresh database). Every file begins with DROP TABLE IF EXISTS, so doing it all again is safe.
            $file = 0;
            $offset = 0;
            $delimiter = ';';
            $done = 0;
            $firstTable = $lastTable = null;
            ++$restarts;
        }

        $marker = $directory . '/' . self::MARKER;
        if (is_file($marker)) {
            // the previous tick did not end in order: whatever of the current file ran is unknown, so it starts over
            if ($offset > 0) {
                ++$restarts;
            }
            $offset = 0;
            $delimiter = ';';
        }

        if ($file < count($files)) {
            if (@file_put_contents($marker, (string) $file) === false) {
                throw new ImportException("Cannot write \"$marker\".");
            }
            $this->runSession();
            // a tick runs at least one statement: the budget only counts once it has done something
            while ($file < count($files) && !($statements > $statementsAtStart && $budget->exhausted())) {
                $splitter = new SqlSplitter($directory . '/' . $files[$file], $offset, $delimiter);
                try {
                    while (($statement = $splitter->next()) !== null) {
                        $created = $this->execute($statement, $rewriter, $files[$file], $offset);
                        if ($created !== null) {
                            $firstTable ??= $created;
                            $lastTable = $created;
                        }
                        $offset = $statement->end;
                        $delimiter = $statement->delimiter;
                        ++$statements;
                        $budget->note(sprintf('importing the database: file %d of %d, %d statements in this step%s', $file + 1, count($files), $statements - $statementsAtStart, is_string($lastTable) ? ', last table ' . $lastTable : ''));
                        if ($budget->exhausted()) {
                            break 2;
                        }
                    }
                } catch (RuntimeException $e) {
                    if ($e instanceof ImportException) {
                        throw $e;
                    }

                    throw new ImportException("Cannot read \"{$files[$file]}\" at byte $offset: " . $e->getMessage(), 0, $e);
                } finally {
                    $splitter->close();
                }
                // the file is finished
                $done += $sizes[$file];
                ++$file;
                $offset = 0;
                $delimiter = ';';
            }
            @unlink($marker);
        }

        $finished = $file >= count($files);
        $state['file'] = $file;
        $state['offset'] = $offset;
        $state['delimiter'] = $delimiter;
        $state['statements'] = $statements;
        $state['restarts'] = $restarts;
        $state['firstTable'] = $firstTable;
        $state['lastTable'] = $lastTable;
        $state['bytes'] = $finished ? (int) $state['total'] : $done + $offset;

        return new TickOutcome($state, $finished, $finished
            ? sprintf('imported %d statements from %d files', $statements, count($files))
            : sprintf('importing %s (file %d of %d, %d%%)', $files[$file], $file + 1, count($files), (int) floor(100 * $state['bytes'] / max(1, (int) $state['total']))));
    }

    /** Whether the first and the latest table this import made are in the database (nothing to look for: yes). */
    private function tablesExist(?string $first, ?string $last): bool
    {
        $names = array_values(array_unique(array_filter([$first, $last], 'is_string')));
        if ($names === []) {
            return true;
        }
        $found = $this->db->select(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME IN (" . implode(', ', array_fill(0, count($names), '?')) . ')',
            $names,
        );

        return count($found) === count($names);
    }

    private function runSession(): void
    {
        foreach (explode(";\n", rtrim(SqlWriter::session(), "\n")) as $setting) {
            $this->db->run(rtrim($setting, ';'));
        }
    }

    /**
     * @throws ImportException
     */
    /**
     * Runs one statement.
     *
     * @return string|null the name of the table, if the statement made one
     *
     * @throws ImportException
     */
    private function execute(SqlStatement $statement, ?PrefixRewriter $rewriter, string $file, int $offset): ?string
    {
        $sql = $rewriter === null ? $statement->text : $rewriter->rewrite($statement->text);
        try {
            $this->db->run($sql);
            if (preg_match('~\A(?:\s|--[^\n]*\n|#[^\n]*\n|/\*(?!!).*?\*/)*(?:INSERT|UPDATE)\b~is', $sql) === 1) {
                $this->assertNothingLost($sql, $file, $offset);
            }

            return preg_match('~\A(?:\s|--[^\n]*\n|#[^\n]*\n|/\*(?!!).*?\*/)*CREATE TABLE `((?:[^`]|``)*)`~is', $sql, $m) === 1 ? str_replace('``', '`', $m[1]) : null;
        } catch (DatabaseError $e) {
            throw new ImportException(sprintf(
                'Importing "%s" failed at byte %d (%s): %s',
                $file,
                $offset,
                PrefixRewriter::describe($sql),
                $e->getMessage(),
            ), 0, $e);
        }
    }

    /**
     * Whether "Data truncated for column 'x' at row n" is the empty value of an ENUM column, which is no loss. An ENUM column that was changed to a
     * list without the empty option (PrestaShop 9 did that for `ps_product.redirect_type`) keeps the rows that held the empty value as the "error
     * value" of the ENUM, index 0, which is read as the empty string. The dump has that string; a server that refuses it in a strict mode, or warns
     * about it in a lenient one, stores the same index 0 again. The table was made from the dump's own definition and the dump holds only values that
     * the server read from the column, so for an ENUM column nothing but the empty string can cause this warning: the state of the source is
     * reproduced as it was.
     */
    private function isEmptyEnumValue(string $message, string $sql): bool
    {
        if (preg_match("~column '((?:[^']|'')*)'~i", $message, $column) !== 1
            || preg_match('~\A(?:\s|--[^\n]*\n|#[^\n]*\n|/\*(?!!).*?\*/)*INSERT INTO `((?:[^`]|``)*)`~is', $sql, $table) !== 1) {
            return false;
        }
        $key = $table[1] . "\0" . $column[1];
        if (!isset($this->enumColumns[$key])) {
            $rows = $this->db->select('SHOW COLUMNS FROM ' . $this->db->quote(str_replace('``', '`', $table[1])) . ' LIKE ' . $this->db->quotedString(str_replace("''", "'", $column[1])));
            $this->enumColumns[$key] = $rows !== [] && stripos((string) ($rows[0]['Type'] ?? ''), 'enum(') === 0;
        }

        return $this->enumColumns[$key];
    }

    /**
     * The dump sets a sql_mode that is not strict, so a server that cannot take a value does not refuse the statement: it changes the
     * value and says so in a warning. A cell larger than max_allowed_packet becomes NULL (or the piece that fits) in the
     * `UPDATE ... CONCAT(...)` that appends its pieces; a value too long for its column is cut. That would be a restore with
     * silently damaged data, so these warnings stop the import.
     *
     * @throws ImportException
     * @throws DatabaseError
     */
    private function assertNothingLost(string $sql, string $file, int $offset): void
    {
        foreach ($this->db->warnings() as $warning) {
            $code = $warning['code'];
            if (!in_array($code, self::DATA_LOSS_CODES, true) || strtolower($warning['level']) === 'note') {
                continue;
            }
            if ($code === 1265 && $this->isEmptyEnumValue($warning['message'], $sql)) {
                continue;
            }
            throw new ImportException(sprintf(
                'Importing "%s" damaged data at byte %d (%s): %s (code %d).%s',
                $file,
                $offset,
                PrefixRewriter::describe($sql),
                $warning['message'],
                $code,
                $code === 1301 ? ' The max_allowed_packet of this database server is smaller than a cell of the backup; raise it (SET GLOBAL max_allowed_packet = ..., or my.cnf) and run the restore again.' : ' The table in the database does not take the value the backup holds.',
            ));
        }
    }
}
