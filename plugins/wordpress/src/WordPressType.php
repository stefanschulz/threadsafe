<?php

declare(strict_types=1);

namespace ThreadSafe\WordPress;

use ThreadSafe\Backup\Manifest;
use ThreadSafe\Cli\Input;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Migration\RequestBuilder as BaseRequestBuilder;
use ThreadSafe\Plugin\Backupable;
use ThreadSafe\Plugin\BackupDefaults;
use ThreadSafe\Plugin\DetectedSite;
use ThreadSafe\Plugin\PluginError;
use ThreadSafe\Plugin\WebsiteType;
use ThreadSafe\WordPress\Backup\WordPressBackup;

/**
 * The website type WordPress (6 and 7): ThreadSafe recognises the installation, reads the database access from `wp-config.php` without
 * running it, leaves out what WordPress and its plugins rebuild and tells the manifest what the site is made of (docs/PHASE9.md).
 *
 * Work package 3 is the backup side. The restore and the migration (work package 4) are not built yet: `migrate wordpress` says so.
 */
final class WordPressType implements WebsiteType, Backupable
{
    public function id(): string
    {
        return 'wordpress';
    }

    public function title(): string
    {
        return 'WordPress';
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
        throw new PluginError('The migration of a WordPress site is not built yet (docs/PHASE9.md, work package 4).');
    }

    public function steps(): array
    {
        throw new PluginError('The migration of a WordPress site is not built yet (docs/PHASE9.md, work package 4).');
    }

    public function detect(string $path): ?DetectedSite
    {
        return (new WordPressBackup())->detect($path);
    }

    public function databaseSettings(string $path): DatabaseSettings
    {
        return (new WordPressBackup())->databaseSettings($path);
    }

    public function defaultFilters(DetectedSite $site): BackupDefaults
    {
        return (new WordPressBackup())->defaultFilters($site);
    }

    public function manifestExtras(DetectedSite $site, ?Connection $db): array
    {
        return (new WordPressBackup())->manifestExtras($site, $db);
    }
}
