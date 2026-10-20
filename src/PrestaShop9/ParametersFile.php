<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9;

use InvalidArgumentException;
use RuntimeException;
use ThreadSafe\PrestaShop9\Exception\InvalidParametersFile;

/**
 * Safe reader and writer for PrestaShop's `app/config/parameters.php`.
 *
 * Reading never executes the file (see {@see ParametersParser}). Writing is surgical: only the text of the value
 * that changes is replaced, so everything else – key order, unknown keys, multi-line values such as PEM keys,
 * the exact formatting – stays byte-identical. New values are rendered with `var_export()`, which is what
 * PrestaShop itself uses, so an edited file is indistinguishable from one written by PrestaShop.
 *
 * Instances are immutable: every `with…()` returns a new instance.
 */
final class ParametersFile
{
    private function __construct(
        private readonly string $source,
        private readonly ParsedParameters $parsed,
    ) {
    }

    public static function fromString(string $source): self
    {
        return new self($source, ParametersParser::parse($source));
    }

    public static function fromPath(string $path): self
    {
        $source = is_file($path) ? @file_get_contents($path) : false;
        if ($source === false) {
            throw new InvalidParametersFile("Cannot read parameters file \"$path\".");
        }

        return self::fromString($source);
    }

    public function toString(): string
    {
        return $this->source;
    }

    /**
     * All parameters; if a key appears more than once the last one wins, as in PHP.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $all = [];
        foreach ($this->parsed->entries as $entry) {
            $all[$entry->key] = $entry->value;
        }

        return $all;
    }

    public function has(string $key): bool
    {
        return $this->entry($key) !== null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $entry = $this->entry($key);

        return $entry === null ? $default : $entry->value;
    }

    /**
     * Returns a copy in which `$key` has the given value. An existing key keeps its position, a new key is
     * appended at the end. The value must be a scalar or null.
     */
    public function with(string $key, string|int|float|bool|null $value): self
    {
        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException('INF and NAN cannot be written to a parameters file.');
        }
        $literal = var_export($value, true);

        $entry = $this->entry($key);
        if ($entry === null) {
            return self::fromString($this->insert($key, $literal));
        }
        if (substr($this->source, $entry->valueStart, $entry->valueEnd - $entry->valueStart) === $literal) {
            return $this;
        }

        $start = $entry->valueStart;
        $separator = '';
        if (is_array($entry->value)) {
            // var_export() puts an array on its own line but a scalar right behind the arrow.
            $start = strlen(rtrim(substr($this->source, 0, $start)));
            $separator = ' ';
        }

        return self::fromString(
            substr($this->source, 0, $start) . $separator . $literal . substr($this->source, $entry->valueEnd),
        );
    }

    /**
     * @param array<string, string|int|float|bool|null> $values
     */
    public function withMany(array $values): self
    {
        $file = $this;
        foreach ($values as $key => $value) {
            $file = $file->with($key, $value);
        }

        return $file;
    }

    /**
     * @throws InvalidParametersFile if a required `database_*` value is missing or has the wrong type
     */
    public function database(): DatabaseSettings
    {
        $string = function (string $key): string {
            $value = $this->get($key);
            if (!is_string($value)) {
                throw new InvalidParametersFile("Parameter \"$key\" is missing or not a string.");
            }

            return $value;
        };

        $port = $this->get('database_port', '3306');
        if (!is_int($port) && !(is_string($port) && ctype_digit($port))) {
            throw new InvalidParametersFile('Parameter "database_port" must be a number.');
        }

        return new DatabaseSettings(
            $string('database_host'),
            (int) $port,
            $string('database_name'),
            $string('database_user'),
            $string('database_password'),
            $string('database_prefix'),
        );
    }

    /** Writes the connection values. The port keeps its existing type (PrestaShop stores it as a string). */
    public function withDatabase(DatabaseSettings $settings): self
    {
        $portIsInt = is_int($this->get('database_port'));

        return $this->withMany([
            'database_host' => $settings->host,
            'database_port' => $portIsInt ? $settings->port : (string) $settings->port,
            'database_name' => $settings->name,
            'database_user' => $settings->user,
            'database_password' => $settings->password,
            'database_prefix' => $settings->prefix,
        ]);
    }

    /**
     * Writes the file atomically (temporary file in the same directory, then rename) and keeps the permissions of
     * an existing file.
     */
    public function writeTo(string $path): void
    {
        $mode = is_file($path) ? (fileperms($path) & 0777) : 0644;
        $temporary = $path . '.threadsafe-' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($temporary, $this->source, LOCK_EX) === false) {
            throw new RuntimeException("Cannot write \"$temporary\".");
        }
        @chmod($temporary, $mode);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException("Cannot replace \"$path\".");
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }

    /** The last entry for the key (the one PHP would use). */
    private function entry(string $key): ?ParameterEntry
    {
        $found = null;
        foreach ($this->parsed->entries as $entry) {
            if ($entry->key === $key) {
                $found = $entry;
            }
        }

        return $found;
    }

    /** Returns the source with a new `'key' => literal,` entry inserted at the end of the `parameters` array. */
    private function insert(string $key, string $literal): string
    {
        $source = $this->source;
        $close = $this->parsed->closeOffset;
        $lastEnd = $this->parsed->lastEntryEnd;
        $needsComma = $lastEnd !== null && !$this->parsed->lastHasComma;
        $entry = var_export($key, true) . ' => ' . $literal . ',';

        $lineStart = strrpos(substr($source, 0, $close), "\n");
        $closeOnOwnLine = $lineStart !== false && trim(substr($source, $lineStart + 1, $close - $lineStart - 1)) === '';

        if ($closeOnOwnLine) {
            $insertAt = $lineStart + 1;
            $source = substr($source, 0, $insertAt) . $this->indent($lastEnd, $lineStart + 1, $close) . $entry . "\n" . substr($source, $insertAt);
            if ($needsComma) {
                $source = substr($source, 0, (int) $lastEnd) . ',' . substr($source, (int) $lastEnd);
            }

            return $source;
        }

        // Everything on one line: array('a' => 1) → array('a' => 1, 'b' => 2)
        if ($lastEnd === null) {
            return substr($source, 0, $close) . rtrim($entry, ',') . substr($source, $close);
        }
        if ($needsComma) {
            return substr($source, 0, $lastEnd) . ', ' . rtrim($entry, ',') . substr($source, $lastEnd);
        }

        return substr($source, 0, $close) . ' ' . $entry . substr($source, $close);
    }

    /** Indentation for a new entry: that of the last entry's line, or two spaces deeper than the closing bracket. */
    private function indent(?int $lastEnd, int $closeLineStart, int $close): string
    {
        if ($lastEnd === null) {
            return substr($this->source, $closeLineStart, $close - $closeLineStart) . '  ';
        }
        $lastEntryLine = $this->parsed->entries[count($this->parsed->entries) - 1];
        $lineStart = strrpos(substr($this->source, 0, $lastEntryLine->valueStart), "\n");
        $line = substr($this->source, $lineStart === false ? 0 : $lineStart + 1, $lastEntryLine->valueStart - ($lineStart === false ? 0 : $lineStart + 1));

        return strlen($line) - strlen(ltrim($line)) > 0 ? substr($line, 0, strlen($line) - strlen(ltrim($line))) : '    ';
    }
}
