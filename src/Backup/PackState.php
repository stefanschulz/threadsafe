<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

use InvalidArgumentException;

/**
 * Where the packing of the archive stands between two ticks: plain data for the run file.
 */
final class PackState
{
    public const string FILES = 'files';

    public const string DATABASE = 'database';

    public const string END = 'end';

    /** What is being packed: the files of the site, then the dump files, then nothing. */
    public string $stage = self::FILES;

    /** Byte offset in the file list of the next item. */
    public int $listOffset = 0;

    /** Index in the list of dump files of the next one. */
    public int $databaseIndex = 0;

    /** Number of the part being written (from 1). */
    public int $part = 1;

    /** @var array<string, mixed>|null state of the {@see \ThreadSafe\Archive\ZipWriter} of the open part; null before the first part */
    public ?array $writer = null;

    /** @var list<array{file: string, size: int, sha256: string, entries: int}> parts that are closed */
    public array $closed = [];

    /** @var array{name: string, path: string, offset: int, size: int, database: bool}|null the file entry in progress: name in the archive, source path, bytes handed to the writer, size at scan time */
    public ?array $open = null;

    public int $files = 0;

    public int $directories = 0;

    public int $fileBytes = 0;

    /** @var list<string> */
    public array $warnings = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'stage' => $this->stage,
            'listOffset' => $this->listOffset,
            'databaseIndex' => $this->databaseIndex,
            'part' => $this->part,
            'writer' => $this->writer,
            'closed' => $this->closed,
            'open' => $this->open,
            'files' => $this->files,
            'directories' => $this->directories,
            'fileBytes' => $this->fileBytes,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        $state = new self();
        $int = static fn (string $key): int => is_int($data[$key] ?? null) ? $data[$key] : throw new InvalidArgumentException("The pack state is invalid ($key).");
        $stage = $data['stage'] ?? null;
        if (!in_array($stage, [self::FILES, self::DATABASE, self::END], true)) {
            throw new InvalidArgumentException('The pack state is invalid (stage).');
        }
        $state->stage = $stage;
        $state->listOffset = $int('listOffset');
        $state->databaseIndex = $int('databaseIndex');
        $state->part = $int('part');
        $state->files = $int('files');
        $state->directories = $int('directories');
        $state->fileBytes = $int('fileBytes');

        $writer = $data['writer'] ?? null;
        if ($writer !== null && !is_array($writer)) {
            throw new InvalidArgumentException('The pack state is invalid (writer).');
        }
        /** @var array<string, mixed>|null $writer */
        $state->writer = $writer;

        foreach (is_array($data['closed'] ?? null) ? $data['closed'] : [] as $part) {
            if (!is_array($part) || !is_string($part['file'] ?? null) || !is_int($part['size'] ?? null) || !is_string($part['sha256'] ?? null) || !is_int($part['entries'] ?? null)) {
                throw new InvalidArgumentException('The pack state is invalid (closed parts).');
            }
            $state->closed[] = ['file' => $part['file'], 'size' => $part['size'], 'sha256' => $part['sha256'], 'entries' => $part['entries']];
        }

        $open = $data['open'] ?? null;
        if ($open !== null) {
            if (!is_array($open) || !is_string($open['name'] ?? null) || !is_string($open['path'] ?? null) || !is_int($open['offset'] ?? null)
                || !is_int($open['size'] ?? null) || !is_bool($open['database'] ?? null)) {
                throw new InvalidArgumentException('The pack state is invalid (open entry).');
            }
            $state->open = ['name' => $open['name'], 'path' => $open['path'], 'offset' => $open['offset'], 'size' => $open['size'], 'database' => $open['database']];
        }

        foreach (is_array($data['warnings'] ?? null) ? $data['warnings'] : [] as $warning) {
            if (is_string($warning)) {
                $state->warnings[] = $warning;
            }
        }

        return $state;
    }
}
