<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

use InvalidArgumentException;

/**
 * The state of a backup run between ticks: plain data for the run file. It holds no secret - the profile is loaded from its
 * store in every tick (and must not change while the run lasts: a hash of it is kept).
 */
final class BackupState
{
    public const string INIT = 'init';

    public const string SCAN = 'scan';

    public const string DUMP = 'dump';

    public const string PACK = 'pack';

    /** Encrypted backups only: the last part is closed with the manifest, then sealed. */
    public const string CLOSE = 'close';

    public const string SEAL = 'seal';

    public const string FINALE = 'finale';

    public const string DONE = 'done';

    /** At most this many warnings are kept; the rest is only counted. */
    public const int MAX_WARNINGS = 200;

    /**
     * The run was started by the web interface, i.e. one process per tick: between two ticks nothing holds its lock, so it is
     * "active" while its last tick is recent (docs/PHASE4.md 3.4). A run of the command line is active while its process lives.
     */
    public bool $web = false;

    /** Who asked for the backup: `manual` or `schedule` (the trigger); it goes into the sidecar file. */
    public string $source = 'manual';

    public string $phase = self::INIT;

    /**
     * The passphrase of a backup that is encrypted for a passphrase and not for the keys of the profile. **Secret**: it lives in the 0600 file
     * of the run for as long as the run lasts and is blanked at the last tick.
     */
    public string $passphrase = '';

    /** @var array<string, mixed>|null the job of the part that is being sealed (secret: the file key is in it) */
    public ?array $seal = null;

    /** @var list<array{file: string, size: int, sha256: string, entries: int}> the sealed parts, in order */
    public array $sealed = [];

    /** When the archive was finished (Unix time); fixed at the closing of the last part, which holds the manifest. */
    public int $finished = 0;

    /** The base name of the backup; empty until the run has started. */
    public string $backup = '';

    public string $profileHash = '';

    /** Start of the run, Unix time. */
    public int $started = 0;

    /** @var list<string> */
    public array $warnings = [];

    public int $warningsDropped = 0;

    /** @var array<string, mixed>|null state of the {@see Scanner} */
    public ?array $scan = null;

    public int $listSize = 0;

    public int $scanFiles = 0;

    public int $scanDirectories = 0;

    public int $scanBytes = 0;

    /** @var array<string, mixed>|null state of the {@see Dumper} */
    public ?array $dump = null;

    /** @var array<string, mixed>|null */
    public ?array $pack = null;

    /** @var array<string, mixed>|null what the finished run reports */
    public ?array $result = null;

    public function __construct(
        public readonly string $profileId,
        public readonly bool $force,
        public readonly bool $snapshot,
        public readonly ?int $partSize,
        public readonly ?int $compression,
    ) {
    }

    public function warn(string $warning): void
    {
        if (count($this->warnings) < self::MAX_WARNINGS) {
            $this->warnings[] = $warning;
        } else {
            ++$this->warningsDropped;
        }
    }

    /**
     * @param list<string> $warnings
     */
    public function warnAll(array $warnings): void
    {
        foreach ($warnings as $warning) {
            $this->warn($warning);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'profileId' => $this->profileId,
            'force' => $this->force,
            'snapshot' => $this->snapshot,
            'web' => $this->web,
            'source' => $this->source,
            'partSize' => $this->partSize,
            'compression' => $this->compression,
            'phase' => $this->phase,
            'passphrase' => $this->phase === self::DONE ? '' : $this->passphrase,
            'seal' => $this->phase === self::DONE ? null : $this->seal,
            'sealed' => $this->sealed,
            'finished' => $this->finished,
            'backup' => $this->backup,
            'profileHash' => $this->profileHash,
            'started' => $this->started,
            'warnings' => $this->warnings,
            'warningsDropped' => $this->warningsDropped,
            'scan' => $this->scan,
            'listSize' => $this->listSize,
            'scanFiles' => $this->scanFiles,
            'scanDirectories' => $this->scanDirectories,
            'scanBytes' => $this->scanBytes,
            'dump' => $this->dump,
            'pack' => $this->pack,
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
        $invalid = static fn (string $key): InvalidArgumentException => new InvalidArgumentException("The backup state is invalid ($key).");
        $string = static fn (string $key): string => is_string($data[$key] ?? null) ? $data[$key] : throw $invalid($key);
        $int = static fn (string $key): int => is_int($data[$key] ?? null) ? $data[$key] : throw $invalid($key);
        $bool = static fn (string $key): bool => is_bool($data[$key] ?? null) ? $data[$key] : throw $invalid($key);
        $maybeInt = static fn (string $key): ?int => ($data[$key] ?? null) === null ? null : (is_int($data[$key]) ? $data[$key] : throw $invalid($key));
        $maybeArray = static function (string $key) use ($data, $invalid): ?array {
            $value = $data[$key] ?? null;
            if ($value !== null && !is_array($value)) {
                throw $invalid($key);
            }

            /** @var array<string, mixed>|null $value */
            return $value;
        };

        $state = new self($string('profileId'), $bool('force'), $bool('snapshot'), $maybeInt('partSize'), $maybeInt('compression'));
        $phase = $string('phase');
        if (!in_array($phase, [self::INIT, self::SCAN, self::DUMP, self::PACK, self::CLOSE, self::SEAL, self::FINALE, self::DONE], true)) {
            throw $invalid('phase');
        }
        $state->web = (($data['web'] ?? false) === true);
        $state->source = ($data['source'] ?? 'manual') === 'schedule' ? 'schedule' : 'manual';
        $state->phase = $phase;
        $state->passphrase = is_string($data['passphrase'] ?? null) ? $data['passphrase'] : '';
        $state->seal = $maybeArray('seal');
        $state->finished = is_int($data['finished'] ?? null) ? $data['finished'] : 0;
        foreach (is_array($data['sealed'] ?? null) ? $data['sealed'] : [] as $part) {
            if (!is_array($part) || !is_string($part['file'] ?? null) || !is_int($part['size'] ?? null) || !is_string($part['sha256'] ?? null) || !is_int($part['entries'] ?? null)) {
                throw $invalid('sealed');
            }
            $state->sealed[] = ['file' => $part['file'], 'size' => $part['size'], 'sha256' => $part['sha256'], 'entries' => $part['entries']];
        }
        $state->backup = $string('backup');
        $state->profileHash = $string('profileHash');
        $state->started = $int('started');
        foreach (is_array($data['warnings'] ?? null) ? $data['warnings'] : [] as $warning) {
            if (is_string($warning)) {
                $state->warnings[] = $warning;
            }
        }
        $state->warningsDropped = $int('warningsDropped');
        $state->scan = $maybeArray('scan');
        $state->listSize = $int('listSize');
        $state->scanFiles = $int('scanFiles');
        $state->scanDirectories = $int('scanDirectories');
        $state->scanBytes = $int('scanBytes');
        $state->dump = $maybeArray('dump');
        $state->pack = $maybeArray('pack');
        $state->result = $maybeArray('result');

        return $state;
    }
}
