<?php

declare(strict_types=1);

namespace ThreadSafe\Plugin;

use ThreadSafe\Database\Connection;
use ThreadSafe\Database\DatabaseSettings;

/**
 * What a website type knows that a backup needs. A type may support migration ({@see WebsiteType}), backup, or both: the
 * entry class of the plugin implements the interfaces it supports. Internal interface, like {@see WebsiteType}.
 */
interface Backupable
{
    /**
     * Looks at the directory and says what application it holds. Reads files only; never starts the application.
     *
     * @return DetectedSite|null null if this is not an installation of the type
     */
    public function detect(string $path): ?DetectedSite;

    /**
     * The database settings from the application's own configuration, read without starting the application (so that a
     * broken site can still be backed up).
     *
     * @return DatabaseSettings|null null if the type has no such configuration; the profile then has to name the database
     *
     * @throws PluginError the configuration exists but cannot be read
     */
    public function databaseSettings(string $path): ?DatabaseSettings;

    /**
     * What is left out by default: paths and tables of which only the structure is kept.
     */
    public function defaultFilters(DetectedSite $site): BackupDefaults;

    /**
     * Facts about the site for the manifest: domains, admin folder, modules ... Plain data, JSON-serialisable. Must not
     * fail when the database is missing ($db null) or the site is broken: it reports what it can.
     *
     * @return array<string, mixed>
     */
    public function manifestExtras(DetectedSite $site, ?Connection $db): array;
}
