<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9;

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

    /**
     * As plain data (JSON), passwords included - for the state file of a run in ticks.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'root' => $this->root,
            'version' => $this->version,
            'adminDirectory' => $this->adminDirectory,
            'configuredDatabase' => $this->configuredDatabase->toArray(),
            'database' => $this->database->toArray(),
            'serverVersion' => $this->serverVersion,
            'shopUrls' => array_map(static fn (ShopUrlRow $r): array => $r->toArray(), $this->shopUrls),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['root'],
            (string) $data['version'],
            isset($data['adminDirectory']) ? (string) $data['adminDirectory'] : null,
            DatabaseSettings::fromArray((array) $data['configuredDatabase']),
            DatabaseSettings::fromArray((array) $data['database']),
            (string) $data['serverVersion'],
            array_map(static fn (mixed $r): ShopUrlRow => ShopUrlRow::fromArray((array) $r), array_values((array) $data['shopUrls'])),
        );
    }
}
