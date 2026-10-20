<?php

declare(strict_types=1);

namespace ThreadSafe\Database;

/**
 * Which database server answered: type and version, read from `SELECT VERSION()`.
 *
 * Supported are MariaDB from 10.2 and MySQL from 5.7 (the minimums of PrestaShop 9); tested are MariaDB 10.11 and
 * MySQL 8.4.
 */
final readonly class ServerInfo
{
    public const string MARIADB = 'mariadb';

    public const string MYSQL = 'mysql';

    public const string UNKNOWN = 'unknown';

    private const string MINIMUM_MARIADB = '10.2';

    private const string MINIMUM_MYSQL = '5.7';

    /**
     * @param string $type    mariadb, mysql or unknown
     * @param string $version numeric version such as "10.11.18" or "8.4.2", empty if unknown
     * @param string $raw     the string the server sent
     */
    public function __construct(public string $type, public string $version, public string $raw)
    {
    }

    /**
     * Understands "10.11.18-MariaDB-ubu2204", "5.5.5-10.11.6-MariaDB" (the prefix some clients get from MariaDB for
     * replication compatibility), "8.4.2", "5.7.44-log" and similar. Forks of MySQL count as MySQL.
     */
    public static function parse(string $raw): self
    {
        if (preg_match('/(\d+\.\d+(?:\.\d+)?)-MariaDB/i', $raw, $m) === 1) {
            return new self(self::MARIADB, $m[1], $raw);
        }
        if (stripos($raw, 'mariadb') !== false) {
            return new self(self::MARIADB, preg_match('/(\d+\.\d+(?:\.\d+)?)/', $raw, $v) === 1 ? $v[1] : '', $raw);
        }
        if (preg_match('/^(\d+\.\d+(?:\.\d+)?)/', trim($raw), $m) === 1) {
            return new self(self::MYSQL, $m[1], $raw);
        }

        return new self(self::UNKNOWN, '', $raw);
    }

    public function isMariaDb(): bool
    {
        return $this->type === self::MARIADB;
    }

    public function isMySql(): bool
    {
        return $this->type === self::MYSQL;
    }

    public function isSupported(): bool
    {
        return match ($this->type) {
            self::MARIADB => version_compare($this->version, self::MINIMUM_MARIADB, '>='),
            self::MYSQL => version_compare($this->version, self::MINIMUM_MYSQL, '>='),
            default => false,
        };
    }

    /** "MariaDB 10.11.18", "MySQL 8.4.2" or the raw string. */
    public function describe(): string
    {
        return match ($this->type) {
            self::MARIADB => 'MariaDB ' . $this->version,
            self::MYSQL => 'MySQL ' . $this->version,
            default => $this->raw === '' ? 'an unknown database server' : '"' . $this->raw . '"',
        };
    }

    /** Why the server is not supported, or null if it is. */
    public function problem(): ?string
    {
        if ($this->isSupported()) {
            return null;
        }
        if ($this->type === self::UNKNOWN) {
            return sprintf('The database server reports %s; only MariaDB (from %s) and MySQL (from %s) are supported.', $this->describe(), self::MINIMUM_MARIADB, self::MINIMUM_MYSQL);
        }

        return sprintf('%s is too old; the minimum is %s.', $this->describe(), $this->isMariaDb() ? 'MariaDB ' . self::MINIMUM_MARIADB : 'MySQL ' . self::MINIMUM_MYSQL);
    }
}
