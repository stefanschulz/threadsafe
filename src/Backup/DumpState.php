<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

use InvalidArgumentException;

/**
 * The state of a {@see Dumper} run between two ticks: plain data that goes into the run file and back. Binary key values
 * are kept as hex, because the run file is JSON.
 */
final class DumpState
{
    public const string INIT = 'init';

    public const string TABLES = 'tables';

    public const string VIEWS = 'views';

    public const string TRIGGERS = 'triggers';

    public const string DONE = 'done';

    public string $phase = self::INIT;

    /** Ticks that did work so far. */
    public int $ticks = 0;

    /** "snapshot": all of it was read inside one transaction snapshot; "table": each table is read for itself. */
    public string $consistency = 'table';

    /** @var list<string> tables in dump order */
    public array $tables = [];

    /** Position in {@see $tables}. */
    public int $index = 0;

    /** @var array{file: string, size: int, key: list<scalar|null>|null, rows: int, chunk: int}|null the table in progress */
    public ?array $current = null;

    /** @var list<array{table: string, file: string, rows: int, bytes: int, structureOnly: bool}> finished tables */
    public array $done = [];

    /** @var list<array{file: string, bytes: int}> the files for views and triggers */
    public array $objectFiles = [];

    /** @var list<array{type: string, name: string}> */
    public array $unsupported = [];

    /** @var list<string> */
    public array $warnings = [];

    public int $rows = 0;

    public int $bytes = 0;

    /**
     * @param list<string> $structureOnly tables (with or without the prefix) of which only the structure is dumped
     */
    public function __construct(
        public readonly string $directory,
        public readonly bool $snapshot,
        public readonly array $structureOnly,
        public readonly int $statementBytes,
        public readonly int $bigCellBytes,
        public readonly int $pieceBytes,
        public int $maxPacket = 0,
    ) {
        $this->consistency = $snapshot ? 'snapshot' : 'table';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'directory' => $this->directory,
            'snapshot' => $this->snapshot,
            'structureOnly' => $this->structureOnly,
            'statementBytes' => $this->statementBytes,
            'bigCellBytes' => $this->bigCellBytes,
            'pieceBytes' => $this->pieceBytes,
            'maxPacket' => $this->maxPacket,
            'phase' => $this->phase,
            'ticks' => $this->ticks,
            'consistency' => $this->consistency,
            'tables' => $this->tables,
            'index' => $this->index,
            'current' => $this->current,
            'done' => $this->done,
            'objectFiles' => $this->objectFiles,
            'unsupported' => $this->unsupported,
            'warnings' => $this->warnings,
            'rows' => $this->rows,
            'bytes' => $this->bytes,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException the data is not a dump state
     */
    public static function fromArray(array $data): self
    {
        $state = new self(
            self::string($data, 'directory'),
            self::bool($data, 'snapshot'),
            self::strings($data, 'structureOnly'),
            self::int($data, 'statementBytes'),
            self::int($data, 'bigCellBytes'),
            self::int($data, 'pieceBytes'),
            self::int($data, 'maxPacket'),
        );
        $state->phase = self::string($data, 'phase');
        $state->ticks = self::int($data, 'ticks');
        $state->consistency = self::string($data, 'consistency');
        $state->tables = self::strings($data, 'tables');
        $state->index = self::int($data, 'index');
        $state->rows = self::int($data, 'rows');
        $state->bytes = self::int($data, 'bytes');
        $state->warnings = self::strings($data, 'warnings');

        $current = $data['current'] ?? null;
        if ($current !== null) {
            if (!is_array($current) || !is_string($current['file'] ?? null) || !is_int($current['size'] ?? null) || !is_int($current['rows'] ?? null)
                || !is_int($current['chunk'] ?? null) || !(($current['key'] ?? null) === null || is_array($current['key']))) {
                throw new InvalidArgumentException('The dump state is invalid (current table).');
            }
            /** @var list<scalar|null>|null $key */
            $key = $current['key'] === null ? null : array_values($current['key']);
            $state->current = ['file' => $current['file'], 'size' => $current['size'], 'key' => $key, 'rows' => $current['rows'], 'chunk' => $current['chunk']];
        }

        foreach (self::list($data, 'done') as $entry) {
            if (!is_array($entry) || !is_string($entry['table'] ?? null) || !is_string($entry['file'] ?? null) || !is_int($entry['rows'] ?? null)
                || !is_int($entry['bytes'] ?? null) || !is_bool($entry['structureOnly'] ?? null)) {
                throw new InvalidArgumentException('The dump state is invalid (finished tables).');
            }
            $state->done[] = ['table' => $entry['table'], 'file' => $entry['file'], 'rows' => $entry['rows'], 'bytes' => $entry['bytes'], 'structureOnly' => $entry['structureOnly']];
        }
        foreach (self::list($data, 'objectFiles') as $entry) {
            if (!is_array($entry) || !is_string($entry['file'] ?? null) || !is_int($entry['bytes'] ?? null)) {
                throw new InvalidArgumentException('The dump state is invalid (object files).');
            }
            $state->objectFiles[] = ['file' => $entry['file'], 'bytes' => $entry['bytes']];
        }
        foreach (self::list($data, 'unsupported') as $entry) {
            if (!is_array($entry) || !is_string($entry['type'] ?? null) || !is_string($entry['name'] ?? null)) {
                throw new InvalidArgumentException('The dump state is invalid (unsupported objects).');
            }
            $state->unsupported[] = ['type' => $entry['type'], 'name' => $entry['name']];
        }

        return $state;
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : throw new InvalidArgumentException("The dump state is invalid ($key).");
    }

    /** @param array<string, mixed> $data */
    private static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : throw new InvalidArgumentException("The dump state is invalid ($key).");
    }

    /** @param array<string, mixed> $data */
    private static function bool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;

        return is_bool($value) ? $value : throw new InvalidArgumentException("The dump state is invalid ($key).");
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<mixed>
     */
    private static function list(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? array_values($value) : throw new InvalidArgumentException("The dump state is invalid ($key).");
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private static function strings(array $data, string $key): array
    {
        $strings = [];
        foreach (self::list($data, $key) as $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException("The dump state is invalid ($key).");
            }
            $strings[] = $value;
        }

        return $strings;
    }
}
