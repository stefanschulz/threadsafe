<?php

declare(strict_types=1);

namespace ThreadSafe\Generic\Backup;

use ThreadSafe\Database\Connection;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Plugin\Backupable;
use ThreadSafe\Plugin\BackupDefaults;
use ThreadSafe\Plugin\DetectedSite;

/**
 * The backup side of "any website": nothing is recognised (the profile names the type), the database is the one the
 * profile names (or none), nothing is left out by default and the manifest gets no extras. It can back up any site as
 * its files plus its database.
 */
final class GenericBackup implements Backupable
{
    public function detect(string $path): ?DetectedSite
    {
        return is_dir($path) ? new DetectedSite('Any website', null) : null;
    }

    public function databaseSettings(string $path): ?DatabaseSettings
    {
        return null;
    }

    public function defaultFilters(DetectedSite $site): BackupDefaults
    {
        return new BackupDefaults();
    }

    public function manifestExtras(DetectedSite $site, ?Connection $db): array
    {
        return [];
    }
}
