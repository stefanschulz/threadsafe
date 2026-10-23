<?php

declare(strict_types=1);

namespace ThreadSafe\Joomla;

use ThreadSafe\Cli\Input;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Joomla\Backup\JoomlaBackup;
use ThreadSafe\Joomla\Steps\NotYetBuilt;
use ThreadSafe\Migration\RequestBuilder as BaseRequestBuilder;
use ThreadSafe\Plugin\Backupable;
use ThreadSafe\Plugin\BackupDefaults;
use ThreadSafe\Plugin\DetectedSite;
use ThreadSafe\Plugin\WebsiteType;

/**
 * The website type Joomla (5 and 6): ThreadSafe recognises the installation, reads the database access from `configuration.php` without
 * running it, leaves out what Joomla rebuilds and tells the manifest what the site is made of. Restoring and migrating are
 * work package 4 of phase 6 (docs/PHASE6.md); until then the migration refuses, and there is no `Restorable`, so a restore of a Joomla
 * backup says that the type cannot do it.
 */
final class JoomlaType implements WebsiteType, Backupable
{
    public function id(): string
    {
        return 'joomla';
    }

    public function title(): string
    {
        return 'Joomla';
    }

    public function options(): array
    {
        return [];
    }

    public function settings(Input $input): array
    {
        return [];
    }

    public function requestBuilder(): BaseRequestBuilder
    {
        return new RequestBuilder();
    }

    public function steps(): array
    {
        return [new NotYetBuilt()];
    }

    public function detect(string $path): ?DetectedSite
    {
        return (new JoomlaBackup())->detect($path);
    }

    public function databaseSettings(string $path): DatabaseSettings
    {
        return (new JoomlaBackup())->databaseSettings($path);
    }

    public function defaultFilters(DetectedSite $site): BackupDefaults
    {
        return (new JoomlaBackup())->defaultFilters($site);
    }

    public function manifestExtras(DetectedSite $site, ?Connection $db): array
    {
        return (new JoomlaBackup())->manifestExtras($site, $db);
    }
}
