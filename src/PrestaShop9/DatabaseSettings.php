<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9;

/**
 * The database connection values stored in `parameters.php` (`database_*`).
 */
final readonly class DatabaseSettings
{
    public function __construct(
        public string $host,
        public int $port,
        public string $name,
        public string $user,
        public string $password,
        public string $prefix,
    ) {
    }
}
