<?php

declare(strict_types=1);

namespace ThreadSafe\WordPress;

use ThreadSafe\Backup\Manifest;
use ThreadSafe\Cli\Input;
use ThreadSafe\Cli\OptionSpec;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Migration\RequestBuilder as BaseRequestBuilder;
use ThreadSafe\Plugin\Backupable;
use ThreadSafe\Plugin\BackupDefaults;
use ThreadSafe\Plugin\DetectedSite;
use ThreadSafe\Generic\Steps\ReplaceContentDomains;
use ThreadSafe\Plugin\Restorable;
use ThreadSafe\Plugin\WebsiteType;
use ThreadSafe\WordPress\Backup\WordPressBackup;
use ThreadSafe\WordPress\Restore\WordPressRestore;
use ThreadSafe\WordPress\Steps\ApplyDevMode;
use ThreadSafe\WordPress\Steps\DiscardCaches;
use ThreadSafe\WordPress\Steps\EnsureConfiguration;
use ThreadSafe\WordPress\Steps\EnsureSkeleton;
use ThreadSafe\WordPress\Steps\FixTablePrefix;
use ThreadSafe\WordPress\Steps\ReadWordPressState;
use ThreadSafe\WordPress\Steps\RewriteHtaccess;
use ThreadSafe\WordPress\Steps\SelfTest;
use ThreadSafe\WordPress\Steps\WriteConfiguration;

/**
 * The website type WordPress (6 and 7): ThreadSafe recognises the installation, reads the database access from `wp-config.php` without
 * running it, leaves out what WordPress and its plugins rebuild and tells the manifest what the site is made of (docs/PHASE9.md).
 *
 * The backup side (work package 3) and the restore and migration side (work package 4): `migrate wordpress` and the restore adapt a copy to its
 * new domain, base path, database and table prefix. The replacement of the old address in the content is the one of the generic type, which
 * this type builds on, with the column `wp_posts.guid` left out (decision 5).
 */
final class WordPressType implements WebsiteType, Backupable, Restorable
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
        return [
            new OptionSpec('path', 'New base path of the site, e.g. /sub/ (default: unchanged); only for a site at the root of its domain', takesValue: true, valueName: 'PATH'),
            new OptionSpec('mode', 'production (default) or dev: the copy sends no mail (wp_mail) and keeps search engines out', takesValue: true, valueName: 'MODE'),
            new OptionSpec('keep-dropins', 'Keep object-cache.php, advanced-cache.php and db.php (default: they are renamed to *.threadsafe-off)'),
            new OptionSpec('include-guid', 'Also replace the old domain in wp_posts.guid (default: the identifiers of the posts are left alone)'),
        ];
    }

    public function settings(Input $input): array
    {
        $settings = [];
        if ($input->has('path')) {
            $settings['path'] = $input->value('path');
        }
        if ($input->has('mode')) {
            $settings['mode'] = $input->value('mode');
        }
        if ($input->has('keep-dropins')) {
            $settings['keepDropIns'] = true;
        }
        if ($input->has('include-guid')) {
            $settings['includeGuid'] = true;
        }

        return $settings;
    }

    public function requestBuilder(): BaseRequestBuilder
    {
        return new RequestBuilder();
    }

    public function steps(): array
    {
        return [
            new EnsureConfiguration(),
            new ReadWordPressState(),
            new EnsureSkeleton(),
            new WriteConfiguration(),
            new ReplaceContentDomains(),
            new FixTablePrefix(),
            new RewriteHtaccess(),
            new DiscardCaches(),
            new ApplyDevMode(),
            new SelfTest(),
        ];
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

    public function fields(Manifest $manifest): array
    {
        return (new WordPressRestore())->fields($manifest);
    }

    public function restoreSettings(array $values): array
    {
        return (new WordPressRestore())->restoreSettings($values);
    }

    public function sourceDomain(Manifest $manifest): ?string
    {
        return (new WordPressRestore())->sourceDomain($manifest);
    }

    public function restoreChecks(Manifest $manifest): array
    {
        return (new WordPressRestore())->restoreChecks($manifest);
    }

    public function links(Manifest $manifest, string $root, array $values, string $servedPath): array
    {
        return (new WordPressRestore())->links($manifest, $root, $values, $servedPath);
    }
}
