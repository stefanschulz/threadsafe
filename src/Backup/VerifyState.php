<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

use InvalidArgumentException;

/**
 * The state of a {@see VerifyRun} between two ticks, as plain data.
 */
final class VerifyState
{
    public const string START = 'start';

    public const string HASH = 'hash';

    public const string ZIP = 'zip';

    public const string DONE = 'done';

    /** More problems than this are counted but not kept: a ruined archive would otherwise fill the state file. */
    public const int MAX_PROBLEMS = 100;

    public string $stage = self::START;

    /** Index of the part in work. */
    public int $part = 0;

    /** @var array{offset: int, context: string}|null the running SHA-256 of the part in work */
    public ?array $hash = null;

    /** @var array{from: int, position: int, files: int, directories: int, tables: int, lastOffset: int, lastEnd: int, ordered: bool}|null the walk through the part in work */
    public ?array $zip = null;

    public int $files = 0;

    public int $directories = 0;

    public int $tables = 0;

    /** Bytes of the parts whose size was right (what the report names as read). */
    public int $bytes = 0;

    /** For the progress bar: bytes to read and bytes read, both passes together. */
    public int $total = 0;

    public int $done = 0;

    /** @var list<string> */
    public array $problems = [];

    public int $problemsDropped = 0;

    /** @var list<string> */
    public array $notes = [];

    public int $partCount = 0;

    public string $status = '';

    /** @var array<string, mixed>|null the report, when the run has ended */
    public ?array $result = null;

    public function __construct(public readonly string $profileId, public readonly string $backup, public bool $full)
    {
    }

    public function problem(string $problem): void
    {
        if (count($this->problems) < self::MAX_PROBLEMS) {
            $this->problems[] = $problem;
        } else {
            ++$this->problemsDropped;
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'profileId' => $this->profileId,
            'backup' => $this->backup,
            'full' => $this->full,
            'stage' => $this->stage,
            'part' => $this->part,
            'hash' => $this->hash,
            'zip' => $this->zip,
            'files' => $this->files,
            'directories' => $this->directories,
            'tables' => $this->tables,
            'bytes' => $this->bytes,
            'total' => $this->total,
            'done' => $this->done,
            'problems' => $this->problems,
            'problemsDropped' => $this->problemsDropped,
            'notes' => $this->notes,
            'partCount' => $this->partCount,
            'status' => $this->status,
            'result' => $this->result,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        $invalid = static fn (string $key): InvalidArgumentException => new InvalidArgumentException("The verification state is invalid ($key).");
        $string = static fn (string $key): string => is_string($data[$key] ?? null) ? $data[$key] : throw $invalid($key);
        $int = static fn (string $key): int => is_int($data[$key] ?? null) ? $data[$key] : throw $invalid($key);
        $strings = static function (string $key) use ($data, $invalid): array {
            $out = [];
            foreach (is_array($data[$key] ?? null) ? $data[$key] : throw $invalid($key) as $value) {
                $out[] = is_string($value) ? $value : throw $invalid($key);
            }

            return $out;
        };

        $state = new self($string('profileId'), $string('backup'), ($data['full'] ?? null) === true);
        $stage = $string('stage');
        if (!in_array($stage, [self::START, self::HASH, self::ZIP, self::DONE], true)) {
            throw $invalid('stage');
        }
        $state->stage = $stage;
        $state->part = $int('part');
        $hash = $data['hash'] ?? null;
        if ($hash !== null) {
            if (!is_array($hash) || !is_int($hash['offset'] ?? null) || !is_string($hash['context'] ?? null)) {
                throw $invalid('hash');
            }
            $state->hash = ['offset' => $hash['offset'], 'context' => $hash['context']];
        }
        $zip = $data['zip'] ?? null;
        if ($zip !== null) {
            if (!is_array($zip)) {
                throw $invalid('zip');
            }
            foreach (['from', 'position', 'files', 'directories', 'tables', 'lastOffset', 'lastEnd'] as $key) {
                if (!is_int($zip[$key] ?? null)) {
                    throw $invalid('zip');
                }
            }
            $state->zip = ['from' => $zip['from'], 'position' => $zip['position'], 'files' => $zip['files'], 'directories' => $zip['directories'], 'tables' => $zip['tables'],
                'lastOffset' => $zip['lastOffset'], 'lastEnd' => $zip['lastEnd'], 'ordered' => ($zip['ordered'] ?? true) === true];
        }
        $state->files = $int('files');
        $state->directories = $int('directories');
        $state->tables = $int('tables');
        $state->bytes = $int('bytes');
        $state->total = $int('total');
        $state->done = $int('done');
        $state->problems = $strings('problems');
        $state->problemsDropped = $int('problemsDropped');
        $state->notes = $strings('notes');
        $state->partCount = $int('partCount');
        $state->status = is_string($data['status'] ?? null) ? $data['status'] : '';
        $result = $data['result'] ?? null;
        if ($result !== null && !is_array($result)) {
            throw $invalid('result');
        }
        /** @var array<string, mixed>|null $result */
        $state->result = $result;

        return $state;
    }
}
