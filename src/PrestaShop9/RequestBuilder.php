<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9;

use Closure;
use InvalidArgumentException;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\ConnectionFailed;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Migration\MailCatcher;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\Migration\Mode;
use ThreadSafe\Migration\SslMode;
use ThreadSafe\PrestaShop9\DevMode\DevModeRules;
use ThreadSafe\PrestaShop9\Exception\InvalidParametersFile;
use ThreadSafe\Replace\DomainMapping;

/**
 * Turns plain settings (from command line flags and/or a JSON request file) into a {@see MigrationRequest}.
 *
 * Recognised settings (all optional except `root`):
 *   root          string   directory of the PrestaShop installation
 *   from, to      string   old and new domain; `from` defaults to the main domain in the shop's database
 *   domains       list     further mappings, each "old=new" or {"from": …, "to": …}
 *   path          string   new base path, e.g. "/shop/"
 *   ssl           string   none | login | all
 *   mode          string   production (default) or dev: cut live mail, payment and cloud connections
 *   mailCatcher   string   dev mode: HOST or HOST:PORT of the SMTP server that receives all mail (default: no mail at all)
 *   devSkip       list     dev mode: ids of rules to leave out (see resources/prestashop9/dev-mode.json)
 *   devRules      string   dev mode: JSON file with more rules
 *   serverPath    string   where the web server sees the shop, if different from root
 *   database      object   host, port, name, user, password, passwordFile, prefix – each defaults to parameters.php
 *   replaceBare   bool     also replace bare domain names
 *   skipTables    list     extra tables to skip (without prefix)
 *   chunkSize     int      rows per chunk
 *
 * The database password is never taken from a plain setting on the command line: use `passwordFile`, the
 * environment variable THREADSAFE_DB_PASSWORD, or put it in the request file.
 */
final class RequestBuilder
{
    /** @var list<string> */
    private array $notes = [];

    /**
     * @param Closure(string): (string|false)|null $getenv for tests; defaults to the real environment
     */
    public function __construct(private readonly ?Closure $getenv = null)
    {
    }

    /**
     * Remarks about decisions taken on the user's behalf ("the shop already uses the new domain").
     *
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @throws InvalidArgumentException a setting is missing or invalid
     * @throws GuardFailed              the shop's database cannot be asked for its current domain
     */
    public function build(array $settings): MigrationRequest
    {
        $known = ['root', 'from', 'to', 'domains', 'path', 'ssl', 'mode', 'mailCatcher', 'devSkip', 'devRules', 'serverPath', 'database', 'replaceBare', 'skipTables', 'chunkSize'];
        foreach (array_keys($settings) as $key) {
            if (!in_array($key, $known, true)) {
                throw new InvalidArgumentException("Unknown setting \"$key\".");
            }
        }

        $root = $this->string($settings, 'root') ?? throw new InvalidArgumentException('The shop root directory (root) is required.');
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            throw new InvalidArgumentException("The shop root \"$root\" is not a directory.");
        }

        $mode = Mode::tryFrom($this->string($settings, 'mode') ?? 'production') ?? throw new InvalidArgumentException('The mode must be "production" or "dev".');
        [$mailCatcher, $devSkip, $devRules] = $this->devSettings($settings, $mode);

        $ssl = null;
        if (($value = $this->string($settings, 'ssl')) !== null) {
            $ssl = SslMode::tryFrom($value) ?? throw new InvalidArgumentException('The SSL mode must be "none", "login" or "all".');
        }

        $database = $this->database($settings['database'] ?? null, $real);
        $domains = $this->domains($settings, $database, $real);

        $skipTables = $settings['skipTables'] ?? [];
        if (!is_array($skipTables) || array_filter($skipTables, static fn (mixed $t): bool => !is_string($t) || $t === '') !== []) {
            throw new InvalidArgumentException('skipTables must be a list of table names.');
        }
        $chunkSize = $settings['chunkSize'] ?? 2000;
        if (!is_int($chunkSize) && !(is_string($chunkSize) && ctype_digit($chunkSize))) {
            throw new InvalidArgumentException('chunkSize must be a number.');
        }
        $replaceBare = $settings['replaceBare'] ?? false;
        if (!is_bool($replaceBare)) {
            throw new InvalidArgumentException('replaceBare must be true or false.');
        }

        return new MigrationRequest(
            $real,
            $domains,
            $database,
            $this->string($settings, 'path'),
            $ssl,
            $mode,
            array_values($skipTables),
            $replaceBare,
            (int) $chunkSize,
            $this->string($settings, 'serverPath'),
            $mailCatcher,
            $devSkip,
            $devRules,
        );
    }

    /**
     * The dev mode settings; they make no sense in production mode, where they would be silently ignored.
     *
     * @param array<string, mixed> $settings
     *
     * @return array{MailCatcher|null, list<string>, string|null}
     */
    private function devSettings(array $settings, Mode $mode): array
    {
        $catcher = $this->string($settings, 'mailCatcher');
        $skip = $settings['devSkip'] ?? [];
        $file = $this->string($settings, 'devRules');
        if (!is_array($skip) || array_filter($skip, static fn (mixed $id): bool => !is_string($id) || $id === '') !== []) {
            throw new InvalidArgumentException('devSkip must be a list of rule ids.');
        }

        if ($mode !== Mode::Dev) {
            if ($catcher !== null || $skip !== [] || $file !== null) {
                throw new InvalidArgumentException('The mail catcher and the dev rule settings need --mode=dev.');
            }

            return [null, [], null];
        }

        $skip = array_values($skip);
        DevModeRules::load($file)->assertKnown($skip);

        return [$catcher === null ? null : MailCatcher::parse($catcher), $skip, $file];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function string(array $settings, string $key): ?string
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
     * The database to use, or null if the request names none (the one in parameters.php is then used).
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
            if (!in_array($key, ['host', 'port', 'name', 'user', 'password', 'passwordFile', 'prefix'], true)) {
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

        $overrides = array_intersect_key($given, array_flip(['host', 'port', 'name', 'user', 'prefix']));
        if ($overrides === [] && $password === null) {
            return null;
        }

        $base = null;
        try {
            $base = ParametersFile::fromPath($root . '/app/config/parameters.php')->database();
        } catch (InvalidParametersFile) {
            // all values must then be given
        }

        $port = $given['port'] ?? $base?->port;
        $values = [
            'host' => $this->string($given, 'host') ?? $base?->host,
            'name' => $this->string($given, 'name') ?? $base?->name,
            'user' => $this->string($given, 'user') ?? $base?->user,
            'password' => $password ?? $base?->password,
            'prefix' => $this->string($given, 'prefix') ?? $base?->prefix,
        ];
        foreach ($values as $name => $value) {
            if ($value === null) {
                throw new InvalidArgumentException("The database $name is not given and parameters.php cannot supply it.");
            }
        }
        if (!is_int($port) && !(is_string($port) && ctype_digit($port))) {
            throw new InvalidArgumentException('The database port must be a number.');
        }

        return new DatabaseSettings((string) $values['host'], (int) $port, (string) $values['name'], (string) $values['user'], (string) $values['password'], (string) $values['prefix']);
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
            $mappings[] = $this->mapping($old, $new);
        }

        if ($from !== null && $to === null) {
            throw new InvalidArgumentException('--from needs --to.');
        }
        if ($to !== null) {
            $from ??= $this->mainDomain($database, $root);
            if (strtolower($from) === strtolower($to)) {
                $this->notes[] = "The shop already uses \"$to\"; no domain has to be replaced.";
            } else {
                $mappings[] = $this->mapping($from, $to);
            }
        }

        return $mappings;
    }

    private function mapping(string $from, string $to): DomainMapping
    {
        return new DomainMapping($from, $to);
    }

    /** The main shop URL's domain, read from the shop's database. */
    private function mainDomain(?DatabaseSettings $database, string $root): string
    {
        try {
            $settings = $database ?? ParametersFile::fromPath($root . '/app/config/parameters.php')->database();
            $connection = Connection::open($settings);
            $table = $connection->table('shop_url');
            $domain = $connection->tableExists($table)
                ? $connection->scalar('SELECT domain FROM ' . $connection->quote($table) . ' WHERE main = 1 ORDER BY id_shop_url LIMIT 1')
                : null;
        } catch (InvalidParametersFile | ConnectionFailed $e) {
            throw new GuardFailed('Cannot find out the shop\'s current domain (give --from): ' . $e->getMessage(), 0, $e);
        }
        if (!is_string($domain) || $domain === '') {
            throw new GuardFailed('The shop has no main shop URL; give the old domain with --from.');
        }
        $this->notes[] = "Old domain taken from the shop's database: $domain";

        return $domain;
    }

    private function environment(string $name): string
    {
        $value = $this->getenv === null ? getenv($name) : ($this->getenv)($name);

        return $value === false ? '' : $value;
    }
}
