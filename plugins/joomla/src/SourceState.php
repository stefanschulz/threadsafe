<?php

declare(strict_types=1);

namespace ThreadSafe\Joomla;

use ThreadSafe\Database\DatabaseSettings;

/**
 * What the steps of a Joomla adaptation know about the site, found by `ReadJoomlaState`: where it is, which version, which database
 * the configuration names and which one the run uses, and the settings of the configuration file that a run may change.
 */
final readonly class SourceState
{
    /**
     * @param string $dbType        the `dbtype` of the configuration (`mysqli` or `pdomysql`)
     * @param string $liveSite      the `live_site` of the configuration ('' if it is empty or not a literal)
     * @param string $logPath       the `log_path` of the configuration ('' if there is none)
     * @param string $tmpPath       the `tmp_path` of the configuration ('' if there is none)
     * @param string $serverVersion the version string that the database server reported
     * @param string $siteName      the `sitename` of the configuration
     * @param string $robots        the `robots` setting ('' = none, the visitors' search engines decide)
     * @param bool   $mailOnline    whether `mailonline` is on (the site sends mail)
     */
    public function __construct(
        public string $root,
        public string $version,
        public DatabaseSettings $configuredDatabase,
        public DatabaseSettings $database,
        public string $dbType,
        public string $liveSite,
        public string $logPath,
        public string $tmpPath,
        public string $serverVersion,
        public string $siteName = '',
        public string $robots = '',
        public bool $mailOnline = true,
    ) {
    }

    public function major(): int
    {
        return (int) explode('.', $this->version)[0];
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
            'dbType' => $this->dbType,
            'liveSite' => $this->liveSite,
            'logPath' => $this->logPath,
            'tmpPath' => $this->tmpPath,
            'serverVersion' => $this->serverVersion,
            'siteName' => $this->siteName,
            'robots' => $this->robots,
            'mailOnline' => $this->mailOnline,
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
            (string) $data['dbType'],
            (string) $data['liveSite'],
            (string) $data['logPath'],
            (string) $data['tmpPath'],
            (string) $data['serverVersion'],
            (string) ($data['siteName'] ?? ''),
            (string) ($data['robots'] ?? ''),
            (bool) ($data['mailOnline'] ?? true),
        );
    }
}
