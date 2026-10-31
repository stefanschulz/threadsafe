<?php

declare(strict_types=1);

namespace ThreadSafe\Migration;

use Closure;
use InvalidArgumentException;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Replace\DomainMapping;

/**
 * Turns plain settings (command line options and/or a JSON request file) into a {@see MigrationRequest}. The settings every website type
 * understands are handled here (`root`, `from`, `to`, `domains`, `serverPath`, `database`, `replaceBare`, `skipTables`, `chunkSize`,
 * `selfTest`, `selfTestUrl`, `selfTestInsecure`; their meaning is in docs/MIGRATE.md); a website type adds its own through three hooks.
 * `from` and every part of `database` default to what the website type reads from the installation.
 *
 * The database password is never taken from a plain setting on the command line: use `passwordFile`, the environment variable
 * THREADSAFE_DB_PASSWORD, or the request file.
 */
abstract class RequestBuilder
{
    private const array COMMON_KEYS = ['root', 'from', 'to', 'domains', 'serverPath', 'database', 'replaceBare', 'skipTables', 'skipColumns', 'chunkSize', 'selfTest', 'selfTestUrl', 'selfTestInsecure'];

    /** @var list<string> */
    private array $notes = [];

    /**
     * @param Closure(string): (string|false)|null $getenv for tests; defaults to the real environment
     */
    public function __construct(private readonly ?Closure $getenv = null)
    {
    }

    /**
     * The settings only this website type knows.
     *
     * @return list<string>
     */
    abstract protected function extraKeys(): array;

    /**
     * Reads and checks the settings of the website type and returns them as named arguments of {@see MigrationRequest}.
     *
     * @param array<string, mixed> $settings all settings
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    abstract protected function extraArguments(array $settings, string $root): array;

    /**
     * The connection values the installation itself holds (e.g. in its configuration file), or null if there are none.
     */
    abstract protected function configuredDatabase(string $root): ?DatabaseSettings;

    /**
     * The table prefix when neither the request nor the installation names one; null = it must be given.
     */
    protected function defaultPrefix(): ?string
    {
        return null;
    }

    /**
     * Whether a new base path (`physicalUri`) is also the path of the new address in the content: a site that moves into a directory writes
     * its links with that directory. A type whose content holds absolute addresses (WordPress) says yes; the default is no, as the content
     * of the other types holds root-relative links or the type rewrites them its own way.
     */
    protected function domainsFollowBasePath(): bool
    {
        return false;
    }

    /**
     * The domain the installation uses now, for `--to` without `--from`.
     *
     * @throws GuardFailed it cannot be found out
     */
    abstract protected function currentDomain(?DatabaseSettings $database, string $root): string;

    /**
     * Remarks about decisions taken on the user's behalf ("the shop already uses the new domain").
     *
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    protected function note(string $note): void
    {
        $this->notes[] = $note;
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @throws InvalidArgumentException a setting is missing or invalid
     * @throws GuardFailed              the current domain cannot be found out
     */
    public function build(array $settings): MigrationRequest
    {
        $known = [...self::COMMON_KEYS, ...$this->extraKeys()];
        foreach (array_keys($settings) as $key) {
            if (!in_array($key, $known, true)) {
                throw new InvalidArgumentException("Unknown setting \"$key\".");
            }
        }

        $root = $this->string($settings, 'root') ?? throw new InvalidArgumentException('The root directory (root) is required.');
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            throw new InvalidArgumentException("The root \"$root\" is not a directory.");
        }

        $extra = $this->extraArguments($settings, $real);

        $database = $this->database($settings['database'] ?? null, $real);
        $domains = $this->domains($settings, $database, $real);
        $base = $extra['physicalUri'] ?? null;
        if ($this->domainsFollowBasePath() && is_string($base) && $base !== '/') {
            $domains = array_map(static fn (DomainMapping $mapping): DomainMapping => $mapping->withPath($base), $domains);
        }

        $skipTables = $settings['skipTables'] ?? [];
        if (!is_array($skipTables) || array_filter($skipTables, static fn (mixed $t): bool => !is_string($t) || $t === '') !== []) {
            throw new InvalidArgumentException('skipTables must be a list of table names.');
        }
        $skipColumns = $settings['skipColumns'] ?? [];
        if (!is_array($skipColumns) || array_filter($skipColumns, static fn (mixed $c): bool => !is_string($c) || preg_match('/^[A-Za-z0-9_]+\.[A-Za-z0-9_]+$/', $c) !== 1) !== []) {
            throw new InvalidArgumentException('skipColumns must be a list of "table.column" (the table without its prefix).');
        }
        $chunkSize = $settings['chunkSize'] ?? 2000;
        if (!is_int($chunkSize) && !(is_string($chunkSize) && ctype_digit($chunkSize))) {
            throw new InvalidArgumentException('chunkSize must be a number.');
        }
        $replaceBare = $settings['replaceBare'] ?? false;
        if (!is_bool($replaceBare)) {
            throw new InvalidArgumentException('replaceBare must be true or false.');
        }

        // The website type's arguments first; the common ones win if a type should name the same.
        return new MigrationRequest(...[
            ...$extra,
            'root' => $real,
            'domains' => $domains,
            'database' => $database,
            'skipTables' => array_values($skipTables),
            'skipColumns' => array_values($skipColumns),
            'replaceBare' => $replaceBare,
            'chunkSize' => (int) $chunkSize,
            'serverPath' => $this->string($settings, 'serverPath'),
            'selfTest' => $this->boolean($settings, 'selfTest', true),
            'selfTestUrl' => $this->selfTestUrl($settings),
            'selfTestInsecure' => $this->boolean($settings, 'selfTestInsecure', false),
        ]);
    }

    /**
     * @param array<string, mixed> $settings
     */
    protected function boolean(array $settings, string $key, bool $default): bool
    {
        $value = $settings[$key] ?? $default;
        if (!is_bool($value)) {
            throw new InvalidArgumentException("$key must be true or false.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function selfTestUrl(array $settings): ?string
    {
        $url = $this->string($settings, 'selfTestUrl');
        if ($url === null) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || !isset($parts['host'])
            || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new InvalidArgumentException('selfTestUrl must look like http://host[:port] (the path of the site is added automatically).');
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $settings
     */
    protected function string(array $settings, string $key): ?string
    {
        $value = $settings[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException("Setting \"$key\" must be a non-empty string.");
        }

        return $value;
    }

    /**
     * The database to use, or null if the request names none (the one the installation holds is then used, if any).
     */
    private function database(mixed $given, string $root): ?DatabaseSettings
    {
        $password = null;
        if ($given === null) {
            $given = [];
        }
        if (!is_array($given)) {
            throw new InvalidArgumentException('Setting "database" must be an object.');
        }
        foreach (array_keys($given) as $key) {
            if (!in_array($key, ['host', 'port', 'name', 'user', 'password', 'passwordFile', 'prefix', 'driver'], true)) {
                throw new InvalidArgumentException("Unknown database setting \"$key\".");
            }
        }

        if (isset($given['passwordFile'])) {
            $file = $given['passwordFile'];
            $content = is_string($file) && is_file($file) ? file_get_contents($file) : false;
            if ($content === false) {
                throw new InvalidArgumentException('The password file cannot be read.');
            }
            $password = rtrim($content, "\r\n");
        } elseif (isset($given['password'])) {
            $password = $this->string($given, 'password');
        } else {
            $fromEnvironment = $this->environment('THREADSAFE_DB_PASSWORD');
            $password = $fromEnvironment !== '' ? $fromEnvironment : null;
        }

        $driver = $this->string($given, 'driver') ?? DatabaseSettings::AUTO;
        if (!in_array($driver, DatabaseSettings::DRIVERS, true)) {
            throw new InvalidArgumentException('The database driver must be ' . implode(', ', DatabaseSettings::DRIVERS) . '.');
        }
        $overrides = array_intersect_key($given, array_flip(['host', 'port', 'name', 'user', 'prefix', 'driver']));
        if ($overrides === [] && $password === null) {
            return null;
        }

        $base = $this->configuredDatabase($root);

        $port = $given['port'] ?? $base?->port;
        $prefixDefault = $base !== null ? $base->prefix : $this->defaultPrefix();
        $values = [
            'host' => $this->string($given, 'host') ?? $base?->host,
            'name' => $this->string($given, 'name') ?? $base?->name,
            'user' => $this->string($given, 'user') ?? $base?->user,
            'password' => $password ?? $base?->password,
            // a site may have no table prefix at all (the empty string), which the other settings may not be
            'prefix' => is_string($given['prefix'] ?? null) ? $given['prefix'] : $prefixDefault,
        ];
        foreach ($values as $name => $value) {
            if ($value === null) {
                throw new InvalidArgumentException("The database $name is not given and cannot be taken from the installation.");
            }
        }
        if (!is_int($port) && !(is_string($port) && ctype_digit($port))) {
            throw new InvalidArgumentException('The database port must be a number.');
        }

        return new DatabaseSettings((string) $values['host'], (int) $port, (string) $values['name'], (string) $values['user'], (string) $values['password'], (string) $values['prefix'], $driver);
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return list<DomainMapping>
     */
    private function domains(array $settings, ?DatabaseSettings $database, string $root): array
    {
        $mappings = [];
        $from = $this->string($settings, 'from');
        $to = $this->string($settings, 'to');

        $list = $settings['domains'] ?? [];
        if (!is_array($list)) {
            throw new InvalidArgumentException('Setting "domains" must be a list.');
        }
        foreach ($list as $entry) {
            if (is_string($entry) && substr_count($entry, '=') === 1) {
                [$old, $new] = explode('=', $entry, 2);
            } elseif (is_array($entry) && is_string($entry['from'] ?? null) && is_string($entry['to'] ?? null)) {
                [$old, $new] = [$entry['from'], $entry['to']];
            } else {
                throw new InvalidArgumentException('Each domain mapping must be "old=new" or {"from": "old", "to": "new"}.');
            }
            $mappings[] = new DomainMapping($old, $new);
        }

        if ($from !== null && $to === null) {
            throw new InvalidArgumentException('--from needs --to.');
        }
        if ($to !== null) {
            $from ??= $this->currentDomain($database, $root);
            if (strtolower($from) === strtolower($to)) {
                $this->note("The site already uses \"$to\"; no domain has to be replaced.");
            } else {
                $mappings[] = new DomainMapping($from, $to);
            }
        }

        return $mappings;
    }

    private function environment(string $name): string
    {
        $value = $this->getenv === null ? getenv($name) : ($this->getenv)($name);

        return $value === false ? '' : $value;
    }
}
