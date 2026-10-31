<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use DateTimeZone;
use JsonException;

/**
 * The settings of the web interface, `data/config.json` (docs/PHASE4.md 3.10): nothing in it is secret, so it can be shown
 * freely (the hash of the administrator's password lives in its own file). Every key has a default; a file holds only what was
 * changed. Unknown keys and values out of range are refused when the file is read, as in the site profiles.
 */
final class Config
{
    /**
     * key => [type, default, minimum, maximum or the allowed values]
     *
     * @var array<string, array{0: string, 1: bool|int|string, 2: int|null, 3: int|list<string>|null}>
     */
    public const array SCHEMA = [
        'web.enabled' => ['bool', false, null, null],
        'web.prettyUrls' => ['bool', true, null, null],
        'web.sessionIdleMinutes' => ['int', 120, 5, 1440],
        'web.requireTwoFactor' => ['bool', false, null, null],
        'download.warnAbove' => ['int', 524288000, 1048576, null],
        'download.accel' => ['enum', 'none', null, ['none', 'sendfile', 'nginx']],
        'download.accelPrefix' => ['prefix', '', null, null],
        'dashboard.warnAfterDays' => ['int', 7, 1, 3650],
        'audit.maxBytes' => ['int', 5242880, 65536, 1073741824],
        'schedule.timezone' => ['timezone', '', null, null],
        'schedule.graceMinutes' => ['int', 360, 1, 10080],
        'schedule.expectedIntervalMinutes' => ['int', 15, 1, 1440],
        'cron.mode' => ['enum', 'auto', null, ['auto', 'worker', 'ticks']],
    ];

    /**
     * @param array<string, bool|int|string> $values only the keys that differ from the defaults
     */
    private function __construct(private readonly array $values)
    {
    }

    /** The defaults, for a data directory without a configuration file. */
    public static function defaults(): self
    {
        return new self([]);
    }

    /**
     * @throws ConfigException the file cannot be read or holds something that is not a setting
     */
    public static function load(string $file): self
    {
        if (!is_file($file)) {
            return self::defaults();
        }
        $content = @file_get_contents($file);
        if ($content === false) {
            throw new ConfigException("Cannot read the configuration file \"$file\".");
        }
        try {
            $data = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ConfigException('The configuration file is not valid JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ConfigException('The configuration file must hold a JSON object.');
        }

        $values = [];
        foreach ($data as $group => $settings) {
            if (!is_string($group) || !is_array($settings) || ($settings !== [] && array_is_list($settings))) {
                throw new ConfigException('The configuration file holds groups of settings, e.g. {"web": {"enabled": true}}.');
            }
            foreach ($settings as $name => $value) {
                $values[$group . '.' . $name] = self::validate($group . '.' . $name, $value);
            }
        }

        return new self($values);
    }

    public function get(string $key): bool|int|string
    {
        if (!isset(self::SCHEMA[$key])) {
            throw new ConfigException("There is no setting \"$key\".");
        }

        return $this->values[$key] ?? self::SCHEMA[$key][1];
    }

    public function enabled(): bool
    {
        return $this->get('web.enabled') === true;
    }

    public function prettyUrls(): bool
    {
        return $this->get('web.prettyUrls') === true;
    }

    /** Whether a login without a second factor reaches the Security page and nothing else (docs/PHASE11.md 3.7). */
    public function requireTwoFactor(): bool
    {
        return $this->get('web.requireTwoFactor') === true;
    }

    public function sessionIdleSeconds(): int
    {
        return (int) $this->get('web.sessionIdleMinutes') * 60;
    }

    public function downloadWarnAbove(): int
    {
        return (int) $this->get('download.warnAbove');
    }

    public function downloadAccel(): string
    {
        return (string) $this->get('download.accel');
    }

    public function downloadAccelPrefix(): string
    {
        return (string) $this->get('download.accelPrefix');
    }

    public function auditMaxBytes(): int
    {
        return (int) $this->get('audit.maxBytes');
    }

    /** The time zone in which cron expressions are read: the setting, or the time zone of PHP (UTC where nobody set one). */
    public function scheduleZone(): DateTimeZone
    {
        $name = (string) $this->get('schedule.timezone');

        return new DateTimeZone($name !== '' ? $name : date_default_timezone_get());
    }

    public function scheduleGraceMinutes(): int
    {
        return (int) $this->get('schedule.graceMinutes');
    }

    /** How often the trigger is meant to come; the warning about a trigger that stays away is derived from it. */
    public function expectedIntervalMinutes(): int
    {
        return (int) $this->get('schedule.expectedIntervalMinutes');
    }

    /** `auto`, `worker` or `ticks`: how the address of the trigger does its work (docs/PHASE5.md 3.3). */
    public function cronMode(): string
    {
        return (string) $this->get('cron.mode');
    }

    /**
     * A copy with one setting changed. The value may be given as text (from a form or the command line).
     *
     * @throws ConfigException the key does not exist or the value is not allowed
     */
    public function with(string $key, mixed $value): self
    {
        $values = $this->values;
        $values[$key] = self::validate($key, $value);

        return new self($values);
    }

    /**
     * Every setting with its current value.
     *
     * @return array<string, bool|int|string>
     */
    public function all(): array
    {
        $all = [];
        foreach (self::SCHEMA as $key => $definition) {
            $all[$key] = $this->values[$key] ?? $definition[1];
        }

        return $all;
    }

    /**
     * @throws ConfigException the file cannot be written
     */
    public function save(string $file): void
    {
        $nested = [];
        foreach ($this->values as $key => $value) {
            [$group, $name] = explode('.', $key, 2);
            $nested[$group][$name] = $value;
        }
        $directory = dirname($file);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new ConfigException("Cannot create the directory \"$directory\".");
        }
        $temporary = $file . '.' . bin2hex(random_bytes(3)) . '.tmp';
        $json = json_encode($nested === [] ? new \stdClass() : $nested, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (@file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new ConfigException("Cannot write the configuration file \"$file\".");
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $file)) {
            @unlink($temporary);

            throw new ConfigException("Cannot write the configuration file \"$file\".");
        }
    }

    private static function validate(string $key, mixed $value): bool|int|string
    {
        if (!isset(self::SCHEMA[$key])) {
            throw new ConfigException("Unknown setting \"$key\" (known: " . implode(', ', array_keys(self::SCHEMA)) . ').');
        }
        [$type, , $minimum, $limit] = self::SCHEMA[$key];

        if ($type === 'bool') {
            if (is_string($value)) {
                $value = match (strtolower($value)) {
                    'true', '1', 'yes', 'on' => true,
                    'false', '0', 'no', 'off' => false,
                    default => $value,
                };
            }
            if (!is_bool($value)) {
                throw new ConfigException("\"$key\" must be true or false.");
            }

            return $value;
        }
        if ($type === 'int') {
            if (is_string($value) && preg_match('/^\d{1,12}$/', $value) === 1) {
                $value = (int) $value;
            }
            if (!is_int($value) || $value < (int) $minimum || (is_int($limit) && $value > $limit)) {
                throw new ConfigException("\"$key\" must be a whole number from $minimum" . (is_int($limit) ? " to $limit" : ' up') . '.');
            }

            return $value;
        }
        if ($type === 'timezone') {
            // empty = the time zone of PHP
            if (!is_string($value) || ($value !== '' && !in_array($value, DateTimeZone::listIdentifiers(), true))) {
                throw new ConfigException("\"$key\" must be empty or a time zone such as Europe/Berlin.");
            }

            return $value;
        }
        if ($type === 'prefix') {
            // the internal location of the web server that serves the files (nginx: X-Accel-Redirect), e.g. "/ts-files/"
            if (!is_string($value) || ($value !== '' && preg_match('~^/[A-Za-z0-9._/-]*/$~', $value) !== 1)) {
                throw new ConfigException("\"$key\" must be empty or a path that starts and ends with a slash, e.g. /ts-files/.");
            }

            return $value;
        }
        if (!is_string($value) || !in_array($value, (array) $limit, true)) {
            throw new ConfigException("\"$key\" must be one of: " . implode(', ', (array) $limit) . '.');
        }

        return $value;
    }
}
