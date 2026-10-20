<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9\Backup;

use ThreadSafe\Core\Resources;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\DatabaseError;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Plugin\Backupable;
use ThreadSafe\Plugin\BackupDefaults;
use ThreadSafe\Plugin\DetectedSite;
use ThreadSafe\Plugin\PluginError;
use ThreadSafe\PrestaShop9\Exception\InvalidParametersFile;
use ThreadSafe\PrestaShop9\Installation;
use ThreadSafe\PrestaShop9\ParametersFile;

/**
 * The backup side of PrestaShop 9: recognises an installation from its files, takes the database settings from
 * `app/config/parameters.php` (read, never executed), leaves out what PrestaShop rebuilds, and tells the manifest which
 * domains, back office folder and modules the shop has.
 */
final class PrestaShop9Backup implements Backupable
{
    public function detect(string $path): ?DetectedSite
    {
        $version = Installation::version($path);
        if ($version === null || (int) explode('.', $version)[0] !== 9) {
            return null;
        }

        return new DetectedSite('PrestaShop', $version, ['adminDirectory' => Installation::adminDirectory($path)]);
    }

    public function databaseSettings(string $path): DatabaseSettings
    {
        try {
            return ParametersFile::fromPath(rtrim($path, '/\\') . '/app/config/parameters.php')->database();
        } catch (InvalidParametersFile $e) {
            throw new PluginError('Cannot read the database settings of the shop: ' . $e->getMessage(), 0, $e);
        }
    }

    public function defaultFilters(DetectedSite $site): BackupDefaults
    {
        $admin = $site->detail('adminDirectory');
        $paths = [];
        foreach (Resources::strings('prestashop9/backup-defaults.json', 'excludePaths') as $path) {
            if (str_contains($path, '{admin}')) {
                if ($admin === null) {
                    continue;   // no back office folder found: nothing to leave out there
                }
                $path = str_replace('{admin}', $admin, $path);
            }
            $paths[] = $path;
        }

        return new BackupDefaults($paths, Resources::strings('prestashop9/backup-defaults.json', 'structureOnlyTables'));
    }

    public function manifestExtras(DetectedSite $site, ?Connection $db): array
    {
        $extras = ['adminDirectory' => $site->detail('adminDirectory')];
        if ($db === null) {
            return $extras;
        }

        try {
            $urls = [];
            foreach ($db->select('SELECT domain, domain_ssl, physical_uri, virtual_uri, main, active FROM ' . $db->quote($db->table('shop_url')) . ' ORDER BY id_shop_url') as $row) {
                $urls[] = [
                    'domain' => (string) $row['domain'],
                    'domainSsl' => (string) $row['domain_ssl'],
                    'physicalUri' => (string) $row['physical_uri'],
                    'virtualUri' => (string) $row['virtual_uri'],
                    'main' => (bool) $row['main'],
                    'active' => (bool) $row['active'],
                ];
            }
            $extras['shopUrls'] = $urls;

            $configuration = [];
            foreach ($db->select('SELECT name, value FROM ' . $db->quote($db->table('configuration')) . " WHERE name IN ('PS_SHOP_DOMAIN', 'PS_SHOP_DOMAIN_SSL', 'PS_SSL_ENABLED', 'PS_SSL_ENABLED_EVERYWHERE') AND id_shop IS NULL AND id_shop_group IS NULL ORDER BY name") as $row) {
                $configuration[(string) $row['name']] = (string) $row['value'];
            }
            $extras['configuration'] = $configuration;

            $modules = [];
            foreach ($db->select('SELECT name, version, active FROM ' . $db->quote($db->table('module')) . ' ORDER BY name') as $row) {
                $modules[] = ['name' => (string) $row['name'], 'version' => (string) $row['version'], 'active' => (bool) $row['active']];
            }
            $extras['modules'] = $modules;
        } catch (DatabaseError $e) {
            $extras['extrasWarning'] = 'Could not read the shop data from the database: ' . $e->getMessage();
        }

        return $extras;
    }
}
