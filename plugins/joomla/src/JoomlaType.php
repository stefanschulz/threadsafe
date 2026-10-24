<?php

declare(strict_types=1);

namespace ThreadSafe\Joomla;

use ThreadSafe\Backup\Manifest;
use ThreadSafe\Cli\Input;
use ThreadSafe\Cli\OptionSpec;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Generic\Steps\ReplaceContentDomains;
use ThreadSafe\Joomla\Backup\JoomlaBackup;
use ThreadSafe\Joomla\Restore\JoomlaRestore;
use ThreadSafe\Joomla\Steps\DiscardCaches;
use ThreadSafe\Joomla\Steps\EnsureSkeleton;
use ThreadSafe\Joomla\Steps\ReadJoomlaState;
use ThreadSafe\Joomla\Steps\RewriteHtaccess;
use ThreadSafe\Joomla\Steps\SelfTest;
use ThreadSafe\Joomla\Steps\WriteConfiguration;
use ThreadSafe\Migration\RequestBuilder as BaseRequestBuilder;
use ThreadSafe\Plugin\Backupable;
use ThreadSafe\Plugin\BackupDefaults;
use ThreadSafe\Plugin\DetectedSite;
use ThreadSafe\Plugin\Restorable;
use ThreadSafe\Plugin\WebsiteType;

/**
 * The website type Joomla (5 and 6): ThreadSafe recognises the installation, reads the database access from `configuration.php` without
 * running it, leaves out what Joomla rebuilds and tells the manifest what the site is made of; and after a restore or a migration it
 * adapts the site to its new host: `configuration.php`, the old address in the content, `.htaccess`, the directories and the caches
 * (docs/PHASE6.md). The replacement of the old address in the content is the one of the generic type, which this type builds on.
 */
final class JoomlaType implements WebsiteType, Backupable, Restorable
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
        return [
            new OptionSpec('path', 'New base path of the site, e.g. /sub/ (default: unchanged)', takesValue: true, valueName: 'PATH'),
        ];
    }

    public function settings(Input $input): array
    {
        return $input->has('path') ? ['path' => $input->value('path')] : [];
    }

    public function requestBuilder(): BaseRequestBuilder
    {
        return new RequestBuilder();
    }

    public function steps(): array
    {
        return [
            new ReadJoomlaState(),
            new EnsureSkeleton(),
            new WriteConfiguration(),
            new ReplaceContentDomains(),
            new RewriteHtaccess(),
            new DiscardCaches(),
            new SelfTest(),
        ];
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

    public function fields(Manifest $manifest): array
    {
        return (new JoomlaRestore())->fields($manifest);
    }

    public function restoreSettings(array $values): array
    {
        return (new JoomlaRestore())->restoreSettings($values);
    }

    public function sourceDomain(Manifest $manifest): ?string
    {
        return (new JoomlaRestore())->sourceDomain($manifest);
    }

    public function restoreChecks(Manifest $manifest): array
    {
        return (new JoomlaRestore())->restoreChecks($manifest);
    }
}
