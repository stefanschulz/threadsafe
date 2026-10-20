<?php

declare(strict_types=1);

namespace ThreadSafe\Migration;

use ThreadSafe\Database\DatabaseSettings;

/**
 * Facts about the installation to be migrated, discovered before anything is planned.
 */
final readonly class SourceState
{
    /**
     * @param list<ShopUrlRow> $shopUrls
     * @param string           $serverVersion version string reported by the database server
     */
    public function __construct(
        public string $root,
        public string $version,
        public ?string $adminDirectory,
        public DatabaseSettings $configuredDatabase,
        public DatabaseSettings $database,
        public string $serverVersion,
        public array $shopUrls,
    ) {
    }

    public function prefix(): string
    {
        return $this->database->prefix;
    }
}
