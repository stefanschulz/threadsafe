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
 * `restarts`, `prefixFrom`, `prefixTo` (null: no change), `bytes` and `total` (for progress).
 */
final class Importer
{
    public const string MARKER = '.import-inflight';

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
                        $this->execute($statement, $rewriter, $files[$file], $offset);
                        $offset = $statement->end;
                        $delimiter = $statement->delimiter;
                        ++$statements;
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
        $state['bytes'] = $finished ? (int) $state['total'] : $done + $offset;

        return new TickOutcome($state, $finished, $finished
            ? sprintf('imported %d statements from %d files', $statements, count($files))
            : sprintf('importing %s (file %d of %d, %d%%)', $files[$file], $file + 1, count($files), (int) floor(100 * $state['bytes'] / max(1, (int) $state['total']))));
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
    private function execute(SqlStatement $statement, ?PrefixRewriter $rewriter, string $file, int $offset): void
    {
        $sql = $rewriter === null ? $statement->text : $rewriter->rewrite($statement->text);
        try {
            $this->db->run($sql);
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
}
