<?php

declare(strict_types=1);

namespace ThreadSafe\Profile;

use ThreadSafe\Backup\Filters;
use ThreadSafe\Database\DatabaseSettings;

/**
 * One site to back up: where it is, what kind of application it is, where its backups go, and what to leave out. The
 * profile is plain data; it is stored by {@see ProfileStore} as `data/profiles/<id>.json`.
 *
 * ```json
 * {
 *     "id": "shop",
 *     "name": "Webshop (live)",
 *     "type": "prestashop9",
 *     "path": "/var/www/shop",
 *     "database": null,
 *     "storage": "/var/backups/threadsafe/shop",
 *     "excludePaths": ["var/log-old"],
 *     "structureOnlyTables": [],
 *     "useDefaultFilters": true,
 *     "compression": 6,
 *     "partSize": null,
 *     "note": "live, shared hosting"
 * }
 * ```
 *
 * `database: null` means: read the credentials from the application's own configuration (PrestaShop: `parameters.php`),
 * so that no second copy of the password exists; a type without such a configuration (`generic`) needs the block
 * `{"host", "port", "name", "user", "password", "prefix", "driver"}`, or `null` for a site without a database. Schedule,
 * retention and encryption are added by later phases.
 */
final readonly class SiteProfile
{
    public const string ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,39}$/';

    private const array KEYS = ['id', 'name', 'type', 'path', 'database', 'storage', 'excludePaths', 'structureOnlyTables', 'useDefaultFilters', 'compression', 'partSize', 'note'];

    private const array DATABASE_KEYS = ['host', 'port', 'name', 'user', 'password', 'prefix', 'driver'];

    /** The smallest part size: a part holds at least a manifest and a few files. */
    public const int MIN_PART_SIZE = 1048576;

    /**
     * @param list<string> $excludePaths         patterns relative to the site root, added to the defaults of the type
     * @param list<string> $structureOnlyTables  tables (with or without prefix) of which only the structure is stored
     * @param int|null     $partSize             bytes per archive part, null = one archive
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public string $path,
        public ?DatabaseSettings $database,
        public string $storage,
        public array $excludePaths = [],
        public array $structureOnlyTables = [],
        public bool $useDefaultFilters = true,
        public int $compression = 6,
        public ?int $partSize = null,
        public string $note = '',
    ) {
    }

    /**
     * @param array<array-key, mixed> $data decoded JSON
     *
     * @throws ProfileException the data is not a valid profile; the message says which field and why
     */
    public static function fromArray(array $data): self
    {
        if (array_is_list($data) && $data !== []) {
            throw new ProfileException('A profile is a JSON object.');
        }
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new ProfileException("Unknown field \"$key\" in the profile (known: " . implode(', ', self::KEYS) . ').');
            }
        }

        $id = self::string($data, 'id', required: true);
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new ProfileException('The profile id must be 1-40 lower-case letters, digits, hyphens or underscores, starting with a letter or digit.');
        }
        $type = self::string($data, 'type', required: true);
        if (preg_match('/^[a-z][a-z0-9_]*$/', $type) !== 1) {
            throw new ProfileException('The website type must be a plugin id such as "prestashop9".');
        }
        $name = trim(self::string($data, 'name', default: $id));
        if ($name === '' || self::hasControlCharacters($name)) {
            throw new ProfileException('The name must not be empty or contain control characters.');
        }
        $note = self::string($data, 'note', default: '');
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $note) === 1) {
            throw new ProfileException('The note must not contain control characters.');
        }

        $compression = $data['compression'] ?? 6;
        if (!is_int($compression) || $compression < 0 || $compression > 9) {
            throw new ProfileException('"compression" must be a number from 0 (store) to 9.');
        }
        $partSize = $data['partSize'] ?? null;
        if ($partSize !== null && (!is_int($partSize) || $partSize < self::MIN_PART_SIZE)) {
            throw new ProfileException('"partSize" must be null or a number of bytes of at least ' . self::MIN_PART_SIZE . '.');
        }
        $useDefaults = $data['useDefaultFilters'] ?? true;
        if (!is_bool($useDefaults)) {
            throw new ProfileException('"useDefaultFilters" must be true or false.');
        }

        $excludePaths = self::strings($data, 'excludePaths');
        foreach ($excludePaths as $pattern) {
            if (($problem = Filters::problem($pattern)) !== null) {
                throw new ProfileException($problem);
            }
        }
        $tables = self::strings($data, 'structureOnlyTables');
        foreach ($tables as $table) {
            if ($table === '' || self::hasControlCharacters($table)) {
                throw new ProfileException('A table name in "structureOnlyTables" must not be empty or contain control characters.');
            }
        }

        return new self(
            $id,
            $name,
            $type,
            self::absolutePath($data, 'path'),
            self::database($data['database'] ?? null),
            self::absolutePath($data, 'storage'),
            $excludePaths,
            $tables,
            $useDefaults,
            $compression,
            $partSize,
            $note,
        );
    }

    /**
     * The profile as data. The password of an explicit database block is part of it only on request, because the stored
     * file needs it and everything shown to a person must not have it.
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $withPassword = true): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'path' => $this->path,
            'database' => $this->database === null ? null : [
                'host' => $this->database->host,
                'port' => $this->database->port,
                'name' => $this->database->name,
                'user' => $this->database->user,
                'password' => $withPassword ? $this->database->password : '********',
                'prefix' => $this->database->prefix,
                'driver' => $this->database->driver,
            ],
            'storage' => $this->storage,
            'excludePaths' => $this->excludePaths,
            'structureOnlyTables' => $this->structureOnlyTables,
            'useDefaultFilters' => $this->useDefaultFilters,
            'compression' => $this->compression,
            'partSize' => $this->partSize,
            'note' => $this->note,
        ];
    }

    /**
     * A copy with some fields changed (validated like a new profile).
     *
     * @param array<string, mixed> $changes field => new value
     *
     * @throws ProfileException
     */
    public function with(array $changes): self
    {
        return self::fromArray([...$this->toArray(), ...$changes]);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function string(array $data, string $key, bool $required = false, string $default = ''): string
    {
        if (!array_key_exists($key, $data)) {
            return $required ? throw new ProfileException("The field \"$key\" is missing.") : $default;
        }
        $value = $data[$key];
        if (!is_string($value) || ($required && $value === '')) {
            throw new ProfileException("The field \"$key\" must be " . ($required ? 'a non-empty ' : 'a ') . 'string.');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<string>
     */
    private static function strings(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new ProfileException("\"$key\" must be a list of strings.");
        }
        $strings = [];
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                throw new ProfileException("\"$key\" must be a list of strings.");
            }
            $strings[] = $entry;
        }

        return array_values(array_unique($strings));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function absolutePath(array $data, string $key): string
    {
        $path = self::string($data, $key, required: true);
        if (str_contains($path, "\0") || (!str_starts_with($path, '/') && preg_match('~^[A-Za-z]:[\\\\/]~', $path) !== 1)) {
            throw new ProfileException("\"$key\" must be an absolute path.");
        }
        if (in_array('..', preg_split('~[\\\\/]+~', $path) ?: [], true)) {
            throw new ProfileException("\"$key\" must not contain \"..\".");
        }

        return strlen($path) > 1 ? rtrim($path, '/\\') : $path;
    }

    private static function database(mixed $value): ?DatabaseSettings
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ProfileException('"database" must be null or an object.');
        }
        foreach (array_keys($value) as $key) {
            if (!in_array($key, self::DATABASE_KEYS, true)) {
                throw new ProfileException("Unknown field \"$key\" in \"database\" (known: " . implode(', ', self::DATABASE_KEYS) . ').');
            }
        }
        $text = static function (string $key, bool $required, string $default = '') use ($value): string {
            $entry = $value[$key] ?? ($required ? null : $default);
            if (!is_string($entry) || ($required && $entry === '')) {
                throw new ProfileException("\"database.$key\" must be a " . ($required ? 'non-empty ' : '') . 'string.');
            }

            return $entry;
        };
        $port = $value['port'] ?? 3306;
        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new ProfileException('"database.port" must be a port number.');
        }
        $driver = $text('driver', false, DatabaseSettings::AUTO);
        if (!in_array($driver, DatabaseSettings::DRIVERS, true)) {
            throw new ProfileException('"database.driver" must be ' . implode(', ', DatabaseSettings::DRIVERS) . '.');
        }
        $prefix = $text('prefix', false);
        if (preg_match('/^[A-Za-z0-9_$]*$/', $prefix) !== 1) {
            throw new ProfileException('"database.prefix" may only contain letters, digits, underscores and dollar signs.');
        }

        return new DatabaseSettings($text('host', false, 'localhost'), $port, $text('name', true), $text('user', true), $text('password', false), $prefix, $driver);
    }

    private static function hasControlCharacters(string $text): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $text) === 1;
    }
}
