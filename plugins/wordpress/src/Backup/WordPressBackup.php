<?php

declare(strict_types=1);

namespace ThreadSafe\WordPress\Backup;

use ThreadSafe\Core\Resources;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\DatabaseError;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Plugin\Backupable;
use ThreadSafe\Plugin\BackupDefaults;
use ThreadSafe\Plugin\DetectedSite;
use ThreadSafe\Plugin\PluginError;
use ThreadSafe\WordPress\Config\WordPressConfigFile;
use ThreadSafe\WordPress\Exception\InvalidConfigFile;
use ThreadSafe\WordPress\Installation;

/**
 * The backup side of WordPress 6 and 7: recognises an installation from its files, takes the database settings from `wp-config.php` (read,
 * never run; also when it lies one directory above the site), leaves out what WordPress and its plugins rebuild, and tells the manifest
 * which version, address, theme, plugins and drop-ins the site has (docs/PHASE9.md 3.3 and 3.4).
 *
 * A **multisite** is recognised and refused: the database settings are refused with a message that says so and names the way to a plain
 * backup (the type "any website"); the restore of a network needs its own rules (decision 2 of the plan).
 */
final class WordPressBackup implements Backupable
{
    public function detect(string $path): ?DetectedSite
    {
        $version = Installation::version($path);
        if ($version === null || !Installation::isSupported($version) || !Installation::isInstalled($path)) {
            return null;
        }

        return new DetectedSite('WordPress', $version, $this->fromFiles($path, $version));
    }

    /**
     * @throws PluginError the configuration cannot be read, the site is a multisite, or the database host is a socket
     */
    public function databaseSettings(string $path): DatabaseSettings
    {
        $found = Installation::configuration($path);
        if ($found === null) {
            throw new PluginError('Cannot read the database settings of the site: there is no wp-config.php in the site directory (or, if the host allows it, the directory above).');
        }
        try {
            $config = WordPressConfigFile::fromPath($found['path']);
            if ($config->isMultisite()) {
                throw new PluginError(self::MULTISITE);
            }

            return $config->database();
        } catch (InvalidConfigFile $e) {
            throw new PluginError('Cannot read the database settings of the site: ' . $e->getMessage(), 0, $e);
        }
    }

    public function defaultFilters(DetectedSite $site): BackupDefaults
    {
        return new BackupDefaults(
            Resources::strings('wordpress/backup-defaults.json', 'excludePaths'),
            Resources::strings('wordpress/backup-defaults.json', 'structureOnlyTables'),
        );
    }

    /**
     * Plain data about the site, never a password or a salt, and never a failure: what cannot be read is reported. The facts of the
     * files were collected by {@see detect()}.
     */
    public function manifestExtras(DetectedSite $site, ?Connection $db): array
    {
        return $site->details + $this->fromDatabase($db);
    }

    private const string MULTISITE = 'This WordPress is a multisite network, which ThreadSafe does not restore yet. Back it up as "Other website" (the type "generic") for a plain backup of the files and the database.';

    /**
     * @return array<string, mixed>
     */
    private function fromFiles(string $root, string $version): array
    {
        $extras = [
            'dbVersion' => Installation::databaseVersion($root),
            'dropIns' => Installation::dropIns($root),
            'mustUsePlugins' => Installation::mustUsePlugins($root),
        ];
        $found = Installation::configuration($root);
        if ($found === null) {
            return $extras + ['configurationWarning' => 'The site has no wp-config.php that could be read.'];
        }
        $extras['configLocation'] = $found['location'];
        try {
            $config = WordPressConfigFile::fromPath($found['path']);
        } catch (InvalidConfigFile $e) {
            return $extras + ['configurationWarning' => $e->getMessage()];
        }
        $extras += [
            'dbPrefix' => $config->tablePrefix(),
            'multisite' => $config->isMultisite(),
            // the constants that override the database: a restore has to change them as well (docs/PHASE9.md 3.2)
            'homeConstant' => $config->has('WP_HOME'),
            'siteurlConstant' => $config->has('WP_SITEURL'),
            'pathConstants' => $config->pathSettingsUnder(rtrim($root, '/\\')),
        ];
        // ABSPATH is computed in every wp-config.php (__DIR__ . '/'); that is no news
        $computed = array_diff_key($config->notUnderstood(), ['ABSPATH' => true]);
        if ($computed !== []) {
            $extras['notUnderstood'] = array_keys($computed);
        }

        return $extras;
    }

    /**
     * @return array<string, mixed>
     */
    private function fromDatabase(?Connection $db): array
    {
        if ($db === null) {
            return [];
        }

        $extras = [];
        try {
            $names = ['siteurl', 'home', 'blogname', 'blog_public', 'db_version', 'template', 'stylesheet', 'active_plugins', 'permalink_structure', 'WPLANG'];
            $options = [];
            foreach ($db->select('SELECT option_name, option_value FROM ' . $db->quote($db->table('options')) . ' WHERE option_name IN (' . implode(', ', array_fill(0, count($names), '?')) . ')', $names) as $row) {
                $options[(string) $row['option_name']] = (string) $row['option_value'];
            }
            $extras['siteUrl'] = $options['siteurl'] ?? null;
            $extras['homeUrl'] = $options['home'] ?? null;
            $extras['siteName'] = $options['blogname'] ?? null;
            $extras['searchEngines'] = ($options['blog_public'] ?? '1') !== '0';
            $extras['dbVersionInDatabase'] = $options['db_version'] ?? null;
            $extras['theme'] = ['template' => $options['template'] ?? null, 'stylesheet' => $options['stylesheet'] ?? null];
            $extras['activePlugins'] = OptionStrings::list($options['active_plugins'] ?? '');
            $extras['permalinks'] = $options['permalink_structure'] ?? '';
            $extras['language'] = ($options['WPLANG'] ?? '') === '' ? 'en_US' : $options['WPLANG'];
            // a network keeps its sites in a table of its own, whatever the file says
            $extras['multisiteTables'] = $db->tableExists($db->table('blogs'));
        } catch (DatabaseError $e) {
            $extras['extrasWarning'] = 'Could not read the site data from the database: ' . $e->getMessage();
        }

        return $extras;
    }
}
