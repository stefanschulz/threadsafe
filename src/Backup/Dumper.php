<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

use InvalidArgumentException;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\DatabaseError;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\Task;
use ThreadSafe\Engine\TickOutcome;

/**
 * Writes the database as SQL files, one per table (`0007_product.sql`: the real table name in the statements, structure first, then the
 * rows), plus `_views.sql` and `_triggers.sql`, into a work directory. A plain `mysql` client imports the files; the backup later puts them
 * into the archive.
 *
 * - **Resumable:** tables are read in pages ordered by the primary key (`WHERE key > last ORDER BY key LIMIT n`); a tick stops between
 *   pages when its budget is used up. A table without a usable primary key is read in one pass as one unit, which may take longer than a
 *   tick.
 * - **Exact values:** {@see SqlWriter} and {@see Column}. FLOAT and DOUBLE are read as `(column + 0e0)`, binary columns as hex, and the
 *   time zone of the connection is UTC so that TIMESTAMP values come out unshifted.
 * - **Large cells:** a cell above `bigCellBytes` is not fetched with the row but read in pieces and written as an `INSERT` with the first
 *   piece and `UPDATE ... CONCAT` for the rest, which keeps every statement within the default `max_allowed_packet` of the importing server
 *   and memory constant.
 * - **Consistency:** with `snapshot` each tick runs inside `START TRANSACTION WITH CONSISTENT SNAPSHOT`: a run done in one tick is
 *   consistent across all tables ("snapshot"), one that needed several is consistent per tick ("table").
 *
 * Parameters of {@see initialState()}: `directory` (must exist), `snapshot`, `structureOnly` (tables of which no rows are dumped), and for
 * tests `statementBytes`, `bigCellBytes`, `pieceBytes`, `maxPacket` (default: the server's `max_allowed_packet`; cells above it are
 * reported).
 */
final class Dumper implements Task
{
    public const int STATEMENT_BYTES = 1048576;

    public const int BIG_CELL_BYTES = 1048576;

    public const int PIECE_BYTES = 2097152;

    private const int MAX_ROWS_PER_STATEMENT = 5000;

    private readonly SchemaReader $schema;

    public function __construct(private readonly Connection $db)
    {
        $this->schema = new SchemaReader($db);
    }

    public function id(): string
    {
        return 'dump';
    }

    public function initialState(array $parameters): array
    {
        $directory = $parameters['directory'] ?? null;
        if (!is_string($directory) || $directory === '' || !is_dir($directory)) {
            throw new InvalidArgumentException('The dump needs the directory it writes into.');
        }
        $structureOnly = [];
        foreach (is_array($parameters['structureOnly'] ?? null) ? $parameters['structureOnly'] : [] as $name) {
            if (is_string($name)) {
                $structureOnly[] = $name;
            }
        }
        $number = static fn (string $key, int $default): int => max(1, is_int($parameters[$key] ?? null) ? $parameters[$key] : $default);

        return (new DumpState(
            rtrim($directory, '/\\'),
            ($parameters['snapshot'] ?? false) === true,
            $structureOnly,
            $number('statementBytes', self::STATEMENT_BYTES),
            $number('bigCellBytes', self::BIG_CELL_BYTES),
            $number('pieceBytes', self::PIECE_BYTES),
            is_int($parameters['maxPacket'] ?? null) ? max(0, $parameters['maxPacket']) : 0,
        ))->toArray();
    }

    public function tick(array $state, Budget $budget): TickOutcome
    {
        $s = DumpState::fromArray($state);
        if ($s->ticks > 0) {
            $s->consistency = 'table';
        }
        ++$s->ticks;

        $this->db->execute("SET time_zone = '+00:00'");
        if ($s->snapshot) {
            $this->db->startSnapshot();
        }
        try {
            $progress = $this->work($s, $budget);
        } finally {
            if ($s->snapshot) {
                try {
                    $this->db->endSnapshot();
                } catch (DatabaseError) {
                    // the connection is gone; the next tick opens a new one
                }
            }
        }

        return new TickOutcome($s->toArray(), $s->phase === DumpState::DONE, $progress);
    }

    private function work(DumpState $s, Budget $budget): string
    {
        if ($s->phase === DumpState::INIT) {
            if ($s->maxPacket <= 0) {
                $s->maxPacket = (int) $this->db->scalar('SELECT @@max_allowed_packet');
            }
            $s->tables = $this->schema->tables();
            $s->unsupported = $this->schema->unsupported();
            $s->phase = DumpState::TABLES;
        }

        while ($s->phase === DumpState::TABLES) {
            if ($s->index >= count($s->tables)) {
                $s->phase = DumpState::VIEWS;
                break;
            }
            $budget->note($this->progress($s));
            $this->dumpTable($s, $budget);
            if ($budget->exhausted()) {
                return $this->progress($s);
            }
        }

        if ($s->phase === DumpState::VIEWS) {
            $this->writeViews($s);
            $s->phase = DumpState::TRIGGERS;
        }
        if ($s->phase === DumpState::TRIGGERS) {
            $this->writeTriggers($s);
            $s->phase = DumpState::DONE;
        }

        return $this->progress($s);
    }

    private function progress(DumpState $s): string
    {
        return match ($s->phase) {
            DumpState::TABLES => sprintf('dumping table %s (%d of %d)', $s->tables[$s->index] ?? '', $s->index + 1, count($s->tables)),
            DumpState::DONE => sprintf('dumped %d tables, %d rows', count($s->done), $s->rows),
            default => 'dumping views and triggers',
        };
    }

    // ---------------------------------------------------------------------------------------------- tables

    /**
     * Works on the current table until it is done or the budget is used up (at least one page is read).
     */
    private function dumpTable(DumpState $s, Budget $budget): void
    {
        $name = $s->tables[$s->index];
        $definition = $this->schema->table($name);
        $structureOnly = $this->isStructureOnly($s, $name);

        if ($s->current === null) {
            $file = SqlWriter::tableFileName($s->index + 1, $this->db->unprefixed($name));
            $out = new AppendFile($s->directory . '/' . $file, 0);
            try {
                $out->write(SqlWriter::tableStart($name, $definition->create));
            } finally {
                $out->close();
            }
            // The first page is a guess; the length of the rows it brings sets the size of the next ones. A table that can hold
            // huge cells starts small, so that a page of such rows does not fill the memory.
            $chunk = min($definition->hasLargeColumns() ? 8 : 500, max(1, intdiv($s->statementBytes, 256)));
            $s->current = ['file' => $file, 'size' => $out->size(), 'key' => null, 'rows' => 0, 'chunk' => $chunk];
        }
        $current = $s->current;

        if (!$structureOnly && $definition->columns !== []) {
            $out = new AppendFile($s->directory . '/' . $current['file'], $current['size']);
            try {
                if ($definition->isKeyed()) {
                    do {
                        $more = $this->keyedPage($s, $definition, $out, $current);
                        $budget->note(sprintf('dumping table %s (%d of %d), %d rows so far', $name, $s->index + 1, count($s->tables), $current['rows']));
                    } while ($more && !$budget->exhausted());
                } else {
                    $this->wholeTable($s, $definition, $out, $current);
                    $more = false;
                }
            } finally {
                $out->close();
            }
            $current['size'] = $out->size();
        } else {
            $more = false;
        }

        if ($more) {
            $s->current = $current;

            return;
        }
        $s->done[] = ['table' => $name, 'file' => $current['file'], 'rows' => $current['rows'], 'bytes' => $current['size'], 'structureOnly' => $structureOnly];
        $s->rows += $current['rows'];
        $s->bytes += $current['size'];
        $s->current = null;
        ++$s->index;
    }

    private function isStructureOnly(DumpState $s, string $table): bool
    {
        return in_array($table, $s->structureOnly, true) || in_array($this->db->unprefixed($table), $s->structureOnly, true);
    }

    /**
     * Reads and writes one page of a table with a primary key.
     *
     * @param array{file: string, size: int, key: list<scalar|null>|null, rows: int, chunk: int} $current
     *
     * @return bool there may be more pages
     */
    private function keyedPage(DumpState $s, TableDefinition $table, AppendFile $out, array &$current): bool
    {
        $key = $table->primaryKey;
        $columns = $table->columns;

        $select = [];
        foreach ($columns as $i => $column) {
            $select[] = $this->selectExpression($column, $s->bigCellBytes, true) . ' AS ' . $this->db->quote("c$i");
            if ($column->isSplit()) {
                $select[] = 'LENGTH(' . $this->db->quote($column->name) . ') AS ' . $this->db->quote("l$i");
            }
        }
        foreach ($key as $i => $column) {
            $select[] = $this->db->quote($column->name) . ' AS ' . $this->db->quote("k$i");
        }

        $parameters = [];
        $where = '';
        if ($current['key'] !== null) {
            [$condition] = SqlWriter::afterKey($key);
            $where = " WHERE $condition";
            $parameters = $this->keyParameters($key, $current['key']);
        }
        $sql = 'SELECT ' . implode(', ', $select) . ' FROM ' . $this->db->quote($table->name) . $where
            . ' ORDER BY ' . implode(', ', array_map(fn (Column $c): string => $this->db->quote($c->name), $key))
            . ' LIMIT ' . $current['chunk'];

        $limit = $current['chunk'];
        $rows = $this->db->select($sql, $parameters);
        if ($rows === []) {
            return false;
        }

        $head = SqlWriter::insertHead($table->name, $columns);
        $group = new RowGroup($head, $s->statementBytes, $out);
        $written = 0;
        $startSize = $out->size();
        foreach ($rows as $row) {
            $rowKey = $this->rowKey($key, $row);
            $literals = [];
            $pieces = [];
            foreach ($columns as $i => $column) {
                $value = $row["c$i"] ?? null;
                if ($column->isSplit() && $value === null && ($row["l$i"] ?? null) !== null && (int) $row["l$i"] > $s->bigCellBytes) {
                    $pieces[$i] = $this->cellLength($table, $column, $rowKey);
                    $literals[$i] = 'NULL';
                    $this->warnIfBeyondPacket($s, $table->name, $column->name, (int) $row["l$i"]);
                    continue;
                }
                $literals[$i] = SqlWriter::literal($value, $column, $column->hexInQuery());
                if ($column->large) {
                    $this->warnIfBeyondPacket($s, $table->name, $column->name, strlen($literals[$i]));
                }
            }

            if ($pieces === []) {
                $group->add('(' . implode(',', $literals) . ')');
            } else {
                $group->flush();
                $this->writeBigRow($s, $table, $out, $head, $literals, $pieces, $rowKey);
            }
            ++$written;
            $current['key'] = $this->storableKey($key, $rowKey);
        }
        $group->flush();

        $current['rows'] += $written;
        $bytes = max(1, $out->size() - $startSize);
        $current['chunk'] = max(1, min(self::MAX_ROWS_PER_STATEMENT, intdiv($s->statementBytes, max(1, intdiv($bytes, $written)))));

        return count($rows) >= $limit;
    }

    /**
     * A row with at least one cell above the limit: the INSERT carries the first piece, UPDATE statements the others.
     *
     * @param array<int, string> $literals the row's literals; large cells are placeholders
     * @param array<int, int>    $pieces   column index => length of the cell in units (bytes, or characters for text)
     * @param list<mixed>        $rowKey   key values as read (raw)
     */
    private function writeBigRow(DumpState $s, TableDefinition $table, AppendFile $out, string $head, array $literals, array $pieces, array $rowKey): void
    {
        foreach ($pieces as $i => $length) {
            $column = $table->columns[$i];
            $literals[$i] = $this->pieceLiteral($table, $column, $rowKey, 1, $this->pieceUnits($column, $s->pieceBytes), $length);
        }
        $out->write($head . '(' . implode(',', $literals) . ");\n");

        $keyLiterals = [];
        foreach ($table->primaryKey as $k => $keyColumn) {
            $keyLiterals[] = SqlWriter::literal($rowKey[$k], $keyColumn, false);
        }
        $untouched = array_values(array_filter($table->columns, static fn (Column $c): bool => $c->onUpdate));

        foreach ($pieces as $i => $length) {
            $column = $table->columns[$i];
            $step = $this->pieceUnits($column, $s->pieceBytes);
            for ($offset = 1 + $step; $offset <= $length; $offset += $step) {
                $literal = $this->pieceLiteral($table, $column, $rowKey, $offset, $step, $length);
                $out->write(SqlWriter::appendPiece($table->name, $column, $literal, $table->primaryKey, $keyLiterals, $untouched));
            }
        }
    }

    /**
     * How much of a cell goes into one piece, in the unit that SUBSTRING counts: bytes for binary columns, characters for
     * text (a character takes at most four bytes, so a piece stays within `pieceBytes`).
     */
    private function pieceUnits(Column $column, int $pieceBytes): int
    {
        return $column->isText() ? max(1, intdiv($pieceBytes, 4)) : $pieceBytes;
    }

    /**
     * @param list<mixed> $rowKey
     */
    private function pieceLiteral(TableDefinition $table, Column $column, array $rowKey, int $offset, int $step, int $length): string
    {
        $name = $this->db->quote($column->name);
        $piece = $this->db->scalar(
            "SELECT SUBSTRING($name, $offset, $step) FROM " . $this->db->quote($table->name) . ' WHERE ' . $this->rowCondition($table),
            $this->scalars($rowKey),
        );
        if (!is_string($piece)) {
            throw new DatabaseError("The row of table \"{$table->name}\" changed while its cell \"{$column->name}\" was read in pieces (offset $offset of $length).");
        }

        return SqlWriter::literal($piece, $column, false);
    }

    /**
     * @param list<mixed> $rowKey
     */
    private function cellLength(TableDefinition $table, Column $column, array $rowKey): int
    {
        $name = $this->db->quote($column->name);
        $length = $this->db->scalar(
            ($column->isText() ? "SELECT CHAR_LENGTH($name)" : "SELECT LENGTH($name)") . ' FROM ' . $this->db->quote($table->name) . ' WHERE ' . $this->rowCondition($table),
            $this->scalars($rowKey),
        );
        if ($length === null) {
            throw new DatabaseError("The row of table \"{$table->name}\" changed while its cell \"{$column->name}\" was measured.");
        }

        return (int) $length;
    }

    /**
     * A table without a usable primary key: one pass over all rows, as one unit of work.
     *
     * @param array{file: string, size: int, key: list<scalar|null>|null, rows: int, chunk: int} $current
     */
    private function wholeTable(DumpState $s, TableDefinition $table, AppendFile $out, array &$current): void
    {
        $columns = $table->columns;
        $select = [];
        foreach ($columns as $i => $column) {
            $select[] = $this->selectExpression($column, $s->bigCellBytes, false) . ' AS ' . $this->db->quote("c$i");
        }

        $group = new RowGroup(SqlWriter::insertHead($table->name, $columns), $s->statementBytes, $out);
        $rows = 0;
        foreach ($this->db->stream('SELECT ' . implode(', ', $select) . ' FROM ' . $this->db->quote($table->name)) as $row) {
            $literals = [];
            foreach ($columns as $i => $column) {
                $literal = SqlWriter::literal($row["c$i"] ?? null, $column, $column->hexInQuery());
                if ($column->large) {
                    $this->warnIfBeyondPacket($s, $table->name, $column->name, strlen($literal));
                }
                $literals[] = $literal;
            }
            $group->add('(' . implode(',', $literals) . ')');
            ++$rows;
        }
        $group->flush();
        $current['rows'] += $rows;
    }

    /**
     * A cell (or the statement that carries it) larger than `max_allowed_packet` of this server cannot be imported by a
     * server with the same limit: not even `UPDATE ... CONCAT` can build a value beyond it (error 1301 on MariaDB, NULL on
     * MySQL). The dump is written anyway; the warning says where a restore needs a higher limit. Once per column.
     */
    private function warnIfBeyondPacket(DumpState $s, string $table, string $column, int $bytes): void
    {
        if ($bytes <= $s->maxPacket) {
            return;
        }
        $start = sprintf('Column "%s"."%s" holds a cell of ', $table, $column);
        foreach ($s->warnings as $existing) {
            if (str_starts_with($existing, $start)) {
                return;
            }
        }
        $s->warnings[] = $start . sprintf(
            '%.1f MB, more than max_allowed_packet of this server (%d MB): importing it needs a server with a higher limit.',
            $bytes / 1048576,
            intdiv($s->maxPacket, 1048576),
        );
    }

    /**
     * How a column is read: floats widened to DOUBLE, binary values as hex, a large cell that is read in pieces only up to
     * the limit (the piece reader fetches the rest).
     */
    private function selectExpression(Column $column, int $bigCellBytes, bool $paged): string
    {
        $name = $this->db->quote($column->name);
        if ($column->kind === ColumnKind::Float) {
            return "($name + 0e0)";
        }
        if ($column->hexInQuery()) {
            return "HEX($name)";
        }
        if ($paged && $column->isSplit()) {
            return "IF(LENGTH($name) > $bigCellBytes, NULL, $name)";
        }

        return $name;
    }

    // ------------------------------------------------------------------------------------------------- keys

    /** `key1 = ? AND key2 = ?`: selects the row whose key values are given in key order. */
    private function rowCondition(TableDefinition $table): string
    {
        return implode(' AND ', array_map(fn (Column $c): string => $this->db->quote($c->name) . ' = ?', $table->primaryKey));
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<scalar|null>
     */
    private function scalars(array $values): array
    {
        return array_map(static fn (mixed $v): int|float|string|bool|null => is_scalar($v) ? $v : null, $values);
    }

    /**
     * @param list<Column>         $key
     * @param array<string, mixed> $row
     *
     * @return list<mixed> the key values as the query returned them
     */
    private function rowKey(array $key, array $row): array
    {
        $values = [];
        foreach (array_keys($key) as $i) {
            $values[] = $row["k$i"] ?? null;
        }

        return $values;
    }

    /**
     * The key in a form that survives JSON: bytes as hex.
     *
     * @param list<Column> $key
     * @param list<mixed>  $values
     *
     * @return list<scalar|null>
     */
    private function storableKey(array $key, array $values): array
    {
        $stored = [];
        foreach ($key as $i => $column) {
            $value = $values[$i] ?? null;
            $stored[] = $column->kind === ColumnKind::Binary && is_string($value) ? bin2hex($value) : (is_scalar($value) ? $value : null);
        }

        return $stored;
    }

    /**
     * Parameters for the condition after a key (see {@see SqlWriter::afterKey()}): the values in the order the condition
     * uses them.
     *
     * @param list<Column>      $key
     * @param list<scalar|null> $stored a key as {@see storableKey()} made it
     *
     * @return list<scalar|null>
     */
    private function keyParameters(array $key, array $stored): array
    {
        $values = [];
        foreach ($key as $i => $column) {
            $value = $stored[$i] ?? null;
            $values[] = $column->kind === ColumnKind::Binary && is_string($value) ? (string) hex2bin($value) : $value;
        }
        if (count($key) === 1) {
            return $values;
        }

        // a > ? OR (a = ? AND b > ?) OR (a = ? AND b = ? AND c > ?) ...
        $parameters = [];
        foreach (array_keys($key) as $i) {
            for ($j = 0; $j <= $i; ++$j) {
                $parameters[] = $values[$j];
            }
        }

        return $parameters;
    }

    // ------------------------------------------------------------------------------------- views and triggers

    private function writeViews(DumpState $s): void
    {
        $views = $this->schema->views();
        if ($views === []) {
            return;
        }
        $text = SqlWriter::header('views');
        foreach ($views as $view) {
            $text .= 'DROP VIEW IF EXISTS ' . SqlWriter::identifier($view['name']) . ";\n" . $view['create'] . ";\n";
        }
        $this->writeObjects($s, '_views.sql', $text);
    }

    private function writeTriggers(DumpState $s): void
    {
        $triggers = $this->schema->triggers();
        if ($triggers === []) {
            return;
        }
        // A trigger body holds semicolons: the file uses the mysql client's DELIMITER command, as mysqldump does.
        $text = SqlWriter::header('triggers');
        foreach ($triggers as $trigger) {
            $text .= 'SET sql_mode = ' . SqlWriter::quote($trigger['sqlMode']) . ";\n"
                . 'DROP TRIGGER IF EXISTS ' . SqlWriter::identifier($trigger['name']) . ";\n"
                . "DELIMITER ;;\n" . $trigger['create'] . " ;;\nDELIMITER ;\n";
        }
        $this->writeObjects($s, '_triggers.sql', $text);
    }

    private function writeObjects(DumpState $s, string $file, string $text): void
    {
        $out = new AppendFile($s->directory . '/' . $file, 0);
        try {
            $out->write($text);
        } finally {
            $out->close();
        }
        $s->objectFiles[] = ['file' => $file, 'bytes' => $out->size()];
        $s->bytes += $out->size();
    }
}
