<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use ThreadSafe\Archive\Entry;
use ThreadSafe\Archive\ZipException;
use ThreadSafe\Archive\ZipReader;

/**
 * What a backup is, written into its last archive part as `threadsafe.json` and, with sizes and checksums of the parts,
 * into the sidecar file. It contains no secret: no database host, user or password, no absolute path of the server.
 *
 * ```json
 * {
 *     "format": 1,
 *     "backupId": "shop_20261020-123456_ab12cd34",
 *     "toolVersion": "0.0.0-dev",
 *     "profile": {"id": "shop", "name": "Webshop"},
 *     "type": "prestashop9",
 *     "application": {"name": "PrestaShop", "version": "9.0.2"},
 *     "php": "8.4.2",
 *     "database": {"server": "mariadb", "version": "10.11.6", "driver": "pdo", "name": "shop", "prefix": "ps_", "charset": "utf8mb4", "collations": ["utf8mb4_general_ci"]},
 *     "consistency": "snapshot",
 *     "extras": {...},
 *     "filters": {"excludePaths": [...], "structureOnlyTables": [...], "literalExcludes": [...]},
 *     "counts": {"files": 0, "directories": 0, "fileBytes": 0, "tables": 0, "rows": 0, "databaseBytes": 0},
 *     "parts": [{"file": "shop_..._ab12cd34.zip", "entries": 0}],
 *     "started": "2026-10-20T12:34:56Z", "finished": "2026-10-20T12:40:00Z",
 *     "warnings": [], "unsupportedObjects": [{"type": "function", "name": "f"}]
 * }
 * ```
 *
 * `database` is empty for a site without a database; `consistency` is `snapshot`, `table` or `none`. The manifest lists the
 * parts by name and entry count; their sizes and SHA-256 sums cannot be inside the part they describe, they are in the
 * sidecar file.
 */
final readonly class Manifest
{
    public const int FORMAT = 1;

    /** The name of the manifest inside the last part. */
    public const string ENTRY = 'threadsafe.json';

    /**
     * @param array{name: string, version: string|null}                                                                                $application
     * @param array<string, mixed>                                                                                                     $database
     * @param array<string, mixed>                                                                                                     $extras
     * @param array{excludePaths: list<string>, structureOnlyTables: list<string>, literalExcludes: list<string>}                       $filters
     * @param array{files: int, directories: int, fileBytes: int, tables: int, rows: int, databaseBytes: int}                            $counts
     * @param list<array{file: string, entries: int}>                                                                                  $parts
     * @param list<string>                                                                                                             $warnings
     * @param list<array{type: string, name: string}>                                                                                  $unsupportedObjects
     */
    public function __construct(
        public string $backupId,
        public string $toolVersion,
        public string $profileId,
        public string $profileName,
        public string $type,
        public array $application,
        public string $php,
        public array $database,
        public string $consistency,
        public array $extras,
        public array $filters,
        public array $counts,
        public array $parts,
        public DateTimeImmutable $started,
        public DateTimeImmutable $finished,
        public array $warnings = [],
        public array $unsupportedObjects = [],
    ) {
    }

    public function duration(): int
    {
        return max(0, $this->finished->getTimestamp() - $this->started->getTimestamp());
    }

    public function name(): ?BackupName
    {
        return BackupName::parse($this->backupId);
    }

    /**
     * A copy with the part list of the finished backup.
     *
     * @param list<array{file: string, entries: int}> $parts
     */
    public function withParts(array $parts): self
    {
        return new self(
            $this->backupId, $this->toolVersion, $this->profileId, $this->profileName, $this->type, $this->application, $this->php,
            $this->database, $this->consistency, $this->extras, $this->filters, $this->counts, $parts, $this->started, $this->finished,
            $this->warnings, $this->unsupportedObjects,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'backupId' => $this->backupId,
            'toolVersion' => $this->toolVersion,
            'profile' => ['id' => $this->profileId, 'name' => $this->profileName],
            'type' => $this->type,
            'application' => $this->application,
            'php' => $this->php,
            'database' => $this->database,
            'consistency' => $this->consistency,
            'extras' => $this->extras,
            'filters' => $this->filters,
            'counts' => $this->counts,
            'parts' => $this->parts,
            'started' => $this->started->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'finished' => $this->finished->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'warnings' => $this->warnings,
            'unsupportedObjects' => $this->unsupportedObjects,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * @param array<array-key, mixed> $data decoded JSON
     *
     * @throws ManifestException the data is not a manifest of this format
     */
    public static function fromArray(array $data): self
    {
        if (($data['format'] ?? null) !== self::FORMAT) {
            throw new ManifestException('Not a ThreadSafe manifest of format ' . self::FORMAT . '.');
        }
        $fail = static fn (string $what): ManifestException => new ManifestException("The manifest is damaged: $what.");

        $string = static function (array $source, string $key) use ($fail): string {
            return is_string($source[$key] ?? null) ? $source[$key] : throw $fail("\"$key\" is missing");
        };
        $section = static function (string $key) use ($data, $fail): array {
            return is_array($data[$key] ?? null) ? $data[$key] : throw $fail("\"$key\" is missing");
        };
        $int = static function (array $source, string $key) use ($fail): int {
            return is_int($source[$key] ?? null) ? $source[$key] : throw $fail("\"$key\" must be a number");
        };
        $strings = static function (mixed $value, string $what) use ($fail): array {
            if (!is_array($value)) {
                throw $fail("$what must be a list");
            }
            $result = [];
            foreach ($value as $entry) {
                if (!is_string($entry)) {
                    throw $fail("$what must be a list of strings");
                }
                $result[] = $entry;
            }

            return $result;
        };
        $time = static function (string $value) use ($fail): DateTimeImmutable {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));

            return $parsed === false ? throw $fail('a time is not valid') : $parsed;
        };

        $profile = $section('profile');
        $application = $section('application');
        $filters = $section('filters');
        $counts = $section('counts');

        $parts = [];
        foreach ($section('parts') as $part) {
            if (!is_array($part) || !is_string($part['file'] ?? null) || !is_int($part['entries'] ?? null)) {
                throw $fail('"parts" is not a list of files');
            }
            $parts[] = ['file' => $part['file'], 'entries' => $part['entries']];
        }
        $unsupported = [];
        foreach ($section('unsupportedObjects') as $object) {
            if (!is_array($object) || !is_string($object['type'] ?? null) || !is_string($object['name'] ?? null)) {
                throw $fail('"unsupportedObjects" is not a list of objects');
            }
            $unsupported[] = ['type' => $object['type'], 'name' => $object['name']];
        }
        $version = $application['version'] ?? null;
        if ($version !== null && !is_string($version)) {
            throw $fail('the application version must be a string or null');
        }

        /** @var array<string, mixed> $database */
        $database = $section('database');
        /** @var array<string, mixed> $extras */
        $extras = $section('extras');

        return new self(
            $string($data, 'backupId'),
            $string($data, 'toolVersion'),
            $string($profile, 'id'),
            $string($profile, 'name'),
            $string($data, 'type'),
            ['name' => $string($application, 'name'), 'version' => $version],
            $string($data, 'php'),
            $database,
            $string($data, 'consistency'),
            $extras,
            [
                'excludePaths' => $strings($filters['excludePaths'] ?? null, '"filters.excludePaths"'),
                'structureOnlyTables' => $strings($filters['structureOnlyTables'] ?? null, '"filters.structureOnlyTables"'),
                'literalExcludes' => $strings($filters['literalExcludes'] ?? null, '"filters.literalExcludes"'),
            ],
            [
                'files' => $int($counts, 'files'),
                'directories' => $int($counts, 'directories'),
                'fileBytes' => $int($counts, 'fileBytes'),
                'tables' => $int($counts, 'tables'),
                'rows' => $int($counts, 'rows'),
                'databaseBytes' => $int($counts, 'databaseBytes'),
            ],
            $parts,
            $time($string($data, 'started')),
            $time($string($data, 'finished')),
            $strings($data['warnings'] ?? null, '"warnings"'),
            $unsupported,
        );
    }

    /**
     * @throws ManifestException
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ManifestException('The manifest is not valid JSON: ' . $e->getMessage(), 0, $e);
        }

        return is_array($data) ? self::fromArray($data) : throw new ManifestException('The manifest is not a JSON object.');
    }

    /**
     * The manifest inside an archive part (the last part of a backup holds it).
     *
     * @throws ManifestException the part cannot be read or holds no manifest
     */
    public static function fromArchive(string $path): self
    {
        try {
            $reader = ZipReader::open($path);
            $entry = $reader->find(self::ENTRY);
            if (!$entry instanceof Entry) {
                throw new ManifestException('The archive part holds no ' . self::ENTRY . ' (not the last part of a backup?).');
            }

            return self::fromJson($reader->contents($entry, 4 * 1048576));
        } catch (ZipException $e) {
            throw new ManifestException('Cannot read the archive: ' . $e->getMessage(), 0, $e);
        }
    }
}
