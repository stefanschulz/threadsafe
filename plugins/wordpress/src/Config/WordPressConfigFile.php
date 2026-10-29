<?php

declare(strict_types=1);

namespace ThreadSafe\WordPress\Config;

use InvalidArgumentException;
use ThreadSafe\Core\AtomicFile;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\WordPress\Exception\InvalidConfigFile;

/**
 * Safe reader and writer for the `wp-config.php` of a WordPress site (docs/PHASE9.md 3.2).
 *
 * Reading never runs the file (see {@see WordPressConfigParser}). Writing is surgical: only the text of the literal that changes is replaced,
 * so everything else - the salts, the settings a site or a plugin added, the comments, the line ends - stays byte for byte what it was. A
 * new value is written the way WordPress writes it (strings in single quotes, `true`, `false`, numbers as they are). The file is written
 * through a temporary file and a rename, which keeps its mode.
 *
 * **Nothing is ever added.** A setting that is not in the file is not made up: {@see with()} refuses, {@see withIfPresent()} leaves the file
 * as it is. A setting that cannot be written back - its value is computed (`getenv_docker(...)`), or it is defined more than once (an `if`
 * with two branches) - is refused with a message that names it and says why, never guessed.
 *
 * The file holds secrets (the password of the database, the salts): nothing here puts a value into a message.
 *
 * Instances are immutable: every `with...()` returns a new instance.
 */
final class WordPressConfigFile
{
    private function __construct(
        private readonly string $source,
        private readonly ParsedConfig $parsed,
    ) {
    }

    public static function fromString(string $source): self
    {
        return new self($source, WordPressConfigParser::parse($source));
    }

    /**
     * @throws InvalidConfigFile the file cannot be read
     */
    public static function fromPath(string $path): self
    {
        $source = is_file($path) ? @file_get_contents($path) : false;
        if ($source === false) {
            throw new InvalidConfigFile("Cannot read the configuration file \"$path\".");
        }

        return self::fromString($source);
    }

    public function toString(): string
    {
        return $this->source;
    }

    /**
     * The settings that are understood, with their value: the constants by their name, the table prefix as `table_prefix`; if a name appears
     * more than once the last one wins.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $all = [];
        foreach ($this->parsed->entries as $entry) {
            if ($entry->understood) {
                $all[$entry->name] = $entry->value;
            }
        }

        return $all;
    }

    /**
     * The settings whose value is more than a literal: they are left alone. For each the function it is computed by, if it is a call.
     *
     * @return array<string, string|null> name => the function (`getenv_docker`), or null
     */
    public function notUnderstood(): array
    {
        $names = [];
        foreach ($this->parsed->entries as $entry) {
            if (!$entry->understood) {
                $names[$entry->name] = $entry->computedBy;
            }
        }

        return $names;
    }

    public function has(string $name): bool
    {
        return $this->entries($name) !== [];
    }

    /** The value of a setting that is a literal, else the default (also when it is computed). */
    public function get(string $name, mixed $default = null): mixed
    {
        $entries = $this->entries($name);
        $entry = $entries === [] ? null : $entries[count($entries) - 1];

        return $entry === null || !$entry->understood ? $default : $entry->value;
    }

    /** Whether the site is a multisite network: `MULTISITE` is true (`WP_ALLOW_MULTISITE` alone only prepares the conversion). */
    public function isMultisite(): bool
    {
        return $this->get('MULTISITE') === true;
    }

    /** The table prefix (`$table_prefix`), null if it is missing or computed. */
    public function tablePrefix(): ?string
    {
        $prefix = $this->get('table_prefix');

        return is_string($prefix) ? $prefix : null;
    }

    /**
     * The database access of the site. The password is part of the result; it never goes into a message.
     *
     * @throws InvalidConfigFile a setting is missing, computed or not a string, or the host is a socket
     */
    public function database(): DatabaseSettings
    {
        $string = function (string $name): string {
            $this->mustBeReadable($name);
            $value = $this->get($name);
            if (!is_string($value)) {
                throw new InvalidConfigFile("The setting \"$name\" is not a string.");
            }

            return $value;
        };
        [$host, $port] = self::splitHost($string('DB_HOST'));
        $this->mustBeReadable('table_prefix');
        $prefix = $this->tablePrefix();
        if ($prefix === null) {
            throw new InvalidConfigFile('The table prefix ($table_prefix) is not a string.');
        }

        return new DatabaseSettings($host, $port, $string('DB_NAME'), $string('DB_USER'), $string('DB_PASSWORD'), $prefix);
    }

    /**
     * Returns a copy in which the setting has the given value.
     *
     * @throws InvalidConfigFile the setting is not in the file, is computed, or is defined more than once
     * @throws InvalidArgumentException a value that cannot be written
     */
    public function with(string $name, string|int|float|bool|null $value): self
    {
        $entry = $this->writable($name);
        $literal = self::literal($value);
        if (substr($this->source, $entry->valueStart, $entry->valueEnd - $entry->valueStart) === $literal) {
            return $this;
        }

        return self::fromString(substr($this->source, 0, $entry->valueStart) . $literal . substr($this->source, $entry->valueEnd));
    }

    /**
     * Like {@see with()}, but a setting that is not in the file stays missing (a restore sets `WP_HOME` only if the site has it).
     *
     * @throws InvalidConfigFile the setting is there but computed or defined more than once
     */
    public function withIfPresent(string $name, string|int|float|bool|null $value): self
    {
        return $this->has($name) ? $this->with($name, $value) : $this;
    }

    /**
     * Writes the connection values: the host (with the port behind it when it is not the usual 3306), the user, the password, the database
     * and the table prefix.
     *
     * @throws InvalidConfigFile one of them is not in the file or cannot be written back
     */
    public function withDatabase(DatabaseSettings $settings): self
    {
        return $this
            ->with('DB_HOST', $settings->port === 3306 ? $settings->host : $settings->host . ':' . $settings->port)
            ->with('DB_USER', $settings->user)
            ->with('DB_PASSWORD', $settings->password)
            ->with('DB_NAME', $settings->name)
            ->with('table_prefix', $settings->prefix);
    }

    /**
     * The names of the settings whose value is a string that begins with the directory (a path the site had: `WPCACHEHOME` of WP Super Cache,
     * `WP_CONTENT_DIR`, `UPLOADS`), in the order of the file.
     *
     * @return list<string>
     */
    public function pathSettingsUnder(string $directory): array
    {
        $names = [];
        foreach ($this->parsed->entries as $entry) {
            if ($entry->kind === ConfigEntry::DEFINE && $entry->understood && is_string($entry->value) && self::isUnder($entry->value, $directory)) {
                $names[$entry->name] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * Returns a copy in which every setting that begins with the old directory begins with the new one (and nothing else changes).
     *
     * @throws InvalidConfigFile such a setting is defined more than once
     */
    public function withDirectoryMoved(string $old, string $new): self
    {
        $file = $this;
        foreach ($this->pathSettingsUnder($old) as $name) {
            $value = $file->get($name);
            if (is_string($value)) {
                $file = $file->with($name, self::rtrimSlash($new) . substr($value, strlen(self::rtrimSlash($old))));
            }
        }

        return $file;
    }

    /**
     * Writes the file atomically and keeps its mode.
     *
     * @see AtomicFile
     */
    public function writeTo(string $path): void
    {
        AtomicFile::write($path, $this->source);
    }

    /**
     * The host and the port of `DB_HOST`: `name`, `name:port` and `[address]:port`. A socket (`:/path`, `name:/path`) and a persistent
     * connection (`p:name`) are not supported.
     *
     * @return array{0: string, 1: int}
     *
     * @throws InvalidConfigFile
     */
    public static function splitHost(string $host): array
    {
        $host = trim($host);
        if ($host === '') {
            throw new InvalidConfigFile('The setting "DB_HOST" is empty.');
        }
        if (str_contains($host, ':/') || str_starts_with($host, 'p:')) {
            throw new InvalidConfigFile('The database host is a socket or a persistent connection ("DB_HOST"); ThreadSafe needs a host name or address, with the port behind a colon if it is not 3306.');
        }
        if (preg_match('/^\[([0-9A-Fa-f:.]+)\](?::(\d{1,5}))?$/', $host, $m) === 1) {
            return [$m[1], isset($m[2]) ? (int) $m[2] : 3306];
        }
        if (substr_count($host, ':') === 1 && preg_match('/^([^:]+):(\d{1,5})$/', $host, $m) === 1) {
            return [$m[1], (int) $m[2]];
        }
        if (substr_count($host, ':') >= 2) {
            return [$host, 3306];   // an IPv6 address without brackets has no port
        }
        if (str_contains($host, ':')) {
            throw new InvalidConfigFile('The port of the database host is not a number ("DB_HOST").');
        }

        return [$host, 3306];
    }

    // ------------------------------------------------------------------------------------------------- helpers

    /** A value as WordPress writes it into the file. */
    private static function literal(string|int|float|bool|null $value): string
    {
        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException('INF and NAN cannot be written to a configuration file.');
        }
        if (is_string($value) && str_contains($value, "\0")) {
            throw new InvalidArgumentException('A value with a NUL byte cannot be written to a configuration file.');
        }

        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            default => var_export($value, true),
        };
    }

    private static function isUnder(string $value, string $directory): bool
    {
        $directory = self::rtrimSlash($directory);

        return $directory !== '' && ($value === $directory || str_starts_with($value, $directory . '/'));
    }

    private static function rtrimSlash(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    /** @return list<ConfigEntry> */
    private function entries(string $name): array
    {
        return array_values(array_filter($this->parsed->entries, static fn (ConfigEntry $entry): bool => $entry->name === $name));
    }

    /** @throws InvalidConfigFile the setting is not there, or its value is computed */
    private function mustBeReadable(string $name): void
    {
        $entries = $this->entries($name);
        if ($entries === []) {
            throw new InvalidConfigFile($name === 'table_prefix' ? 'The file does not set the table prefix ($table_prefix).' : "The setting \"$name\" is not in the file.");
        }
        $last = $entries[count($entries) - 1];
        if (!$last->understood) {
            throw new InvalidConfigFile(sprintf(
                'The setting "%s" is computed%s, not written out; ThreadSafe reads only values that are written out. Give the database in the profile by hand.',
                $name,
                $last->computedBy === null ? '' : ' by ' . $last->computedBy . '()',
            ));
        }
    }

    /** @throws InvalidConfigFile */
    private function writable(string $name): ConfigEntry
    {
        $entries = $this->entries($name);
        if ($entries === []) {
            throw new InvalidConfigFile("The setting \"$name\" is not in the file; ThreadSafe changes settings and does not add them.");
        }
        if (count($entries) > 1) {
            throw new InvalidConfigFile("The setting \"$name\" is defined " . count($entries) . ' times (a condition with several branches?); ThreadSafe cannot tell which one counts and does not change it.');
        }
        $this->mustBeReadable($name);

        return $entries[0];
    }
}
