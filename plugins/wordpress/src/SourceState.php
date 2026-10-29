<?php

declare(strict_types=1);

namespace ThreadSafe\WordPress;

use ThreadSafe\Database\DatabaseSettings;

/**
 * What the steps of a WordPress adaptation know about the site, found by `ReadWordPressState`: where it is, which version, which database
 * the configuration names and which one the run uses, where the configuration file is, and the address the database holds.
 */
final readonly class SourceState
{
    /**
     * @param string $configPath   the `wp-config.php` that was read (in the site directory, or the one above it)
     * @param string $siteUrl      the option `siteurl` of the database ('' if there is none)
     * @param string $homeUrl      the option `home`
     * @param bool   $homeConstant `WP_HOME` is defined in the configuration file (it overrides the database)
     * @param bool   $siteurlConstant `WP_SITEURL` is defined there
     */
    public function __construct(
        public string $root,
        public string $version,
        public DatabaseSettings $configuredDatabase,
        public DatabaseSettings $database,
        public string $configPath,
        public string $siteUrl,
        public string $homeUrl,
        public bool $homeConstant,
        public bool $siteurlConstant,
        public string $serverVersion,
    ) {
    }

    /** Whether the run puts the tables under another prefix than the configuration names. */
    public function prefixChanges(): bool
    {
        return $this->configuredDatabase->prefix !== $this->database->prefix;
    }

    /**
     * As plain data (JSON), passwords included - for the state file of a run in ticks, which is protected like the settings are.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'root' => $this->root,
            'version' => $this->version,
            'configuredDatabase' => $this->configuredDatabase->toArray(),
            'database' => $this->database->toArray(),
            'configPath' => $this->configPath,
            'siteUrl' => $this->siteUrl,
            'homeUrl' => $this->homeUrl,
            'homeConstant' => $this->homeConstant,
            'siteurlConstant' => $this->siteurlConstant,
            'serverVersion' => $this->serverVersion,
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
            DatabaseSettings::fromArray((array) $data['configuredDatabase']),
            DatabaseSettings::fromArray((array) $data['database']),
            (string) $data['configPath'],
            (string) $data['siteUrl'],
            (string) $data['homeUrl'],
            (bool) $data['homeConstant'],
            (bool) $data['siteurlConstant'],
            (string) $data['serverVersion'],
        );
    }
}
