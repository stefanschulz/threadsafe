<?php

declare(strict_types=1);

namespace ThreadSafe\Database;

use InvalidArgumentException;

/**
 * The database connection values: those stored in `parameters.php` (`database_*`) plus the PHP interface to use.
 */
final readonly class DatabaseSettings
{
    /** `pdo_mysql`. */
    public const string PDO = 'pdo';

    /** `mysqli`. */
    public const string MYSQLI = 'mysqli';

    /** `pdo_mysql` if it is loaded, otherwise `mysqli`. */
    public const string AUTO = 'auto';

    public const array DRIVERS = [self::AUTO, self::PDO, self::MYSQLI];

    /**
     * @param string $driver auto, pdo or mysqli (see the constants)
     */
    public function __construct(
        public string $host,
        public int $port,
        public string $name,
        public string $user,
        public string $password,
        public string $prefix,
        public string $driver = self::AUTO,
    ) {
        if (!in_array($driver, self::DRIVERS, true)) {
            throw new InvalidArgumentException('The database driver must be ' . implode(', ', self::DRIVERS) . '.');
        }
    }

    /**
     * All values, the password included: only for a state file that is protected like the settings are.
     *
     * @return array<string, scalar>
     */
    public function toArray(): array
    {
        return ['host' => $this->host, 'port' => $this->port, 'name' => $this->name, 'user' => $this->user, 'password' => $this->password, 'prefix' => $this->prefix, 'driver' => $this->driver];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['host'],
            (int) $data['port'],
            (string) $data['name'],
            (string) $data['user'],
            (string) $data['password'],
            (string) $data['prefix'],
            (string) ($data['driver'] ?? self::AUTO),
        );
    }
}
