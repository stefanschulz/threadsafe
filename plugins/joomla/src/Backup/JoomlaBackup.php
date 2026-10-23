<?php

declare(strict_types=1);

namespace ThreadSafe\Joomla\Backup;

use ThreadSafe\Core\Resources;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\DatabaseError;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Joomla\Config\JoomlaConfigFile;
use ThreadSafe\Joomla\Exception\InvalidConfigFile;
use ThreadSafe\Joomla\Installation;
use ThreadSafe\Plugin\Backupable;
use ThreadSafe\Plugin\BackupDefaults;
use ThreadSafe\Plugin\DetectedSite;
use ThreadSafe\Plugin\PluginError;

/**
 * The backup side of Joomla 5 and 6: recognises an installation from its files, takes the database settings from `configuration.php`
 * (read, never run), leaves out what Joomla rebuilds (caches, `tmp`, logs, sessions, the search index), and tells the manifest which
 * version, extensions, templates, languages and which addresses the site has.
 */
final class JoomlaBackup implements Backupable
{
    public function __construct(private readonly ?AddressCandidates $candidates = null)
    {
    }

    public function detect(string $path): ?DetectedSite
    {
        $version = Installation::version($path);
        if ($version === null || !in_array((int) explode('.', $version)[0], Installation::MAJOR_VERSIONS, true) || !Installation::isInstalled($path)) {
            return null;
        }
        // what the configuration file says that a restore wants to know (never a password or the secret of the site)
        $details = self::fromConfiguration($path);

        return new DetectedSite('Joomla', $version, $details);
    }

    public function databaseSettings(string $path): DatabaseSettings
    {
        try {
            return JoomlaConfigFile::fromPath(Installation::configurationPath($path))->database();
        } catch (InvalidConfigFile $e) {
            throw new PluginError('Cannot read the database settings of the site: ' . $e->getMessage(), 0, $e);
        }
    }

    public function defaultFilters(DetectedSite $site): BackupDefaults
    {
        return new BackupDefaults(
            Resources::strings('joomla/backup-defaults.json', 'excludePaths'),
            Resources::strings('joomla/backup-defaults.json', 'structureOnlyTables'),
        );
    }

    /**
     * Plain data about the site, never a password or the `secret` of the site, and never a failure: what cannot be read is reported.
     * The facts of the configuration file were collected by {@see detect()}.
     */
    public function manifestExtras(DetectedSite $site, ?Connection $db): array
    {
        return $site->details + $this->fromDatabase($db);
    }

    /**
     * @return array<string, mixed>
     */
    private static function fromConfiguration(string $root): array
    {
        try {
            $config = JoomlaConfigFile::fromPath(Installation::configurationPath($root));
        } catch (InvalidConfigFile $e) {
            return ['configurationWarning' => $e->getMessage()];
        }
        $extras = [
            'siteName' => self::string($config->get('sitename')),
            'dbType' => self::string($config->get('dbtype')),
            'dbPrefix' => self::string($config->get('dbprefix')),
            'liveSite' => self::string($config->get('live_site')),
            'offline' => $config->get('offline') === true,
            'sef' => $config->get('sef') === true,
            'sefRewrite' => $config->get('sef_rewrite') === true,
        ];
        if ($config->notUnderstood() !== []) {
            $extras['notUnderstood'] = $config->notUnderstood();
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
            $table = $db->quote($db->table('extensions'));
            $core = 0;
            $third = [];
            foreach ($db->select('SELECT type, element, folder, client_id, enabled, manifest_cache FROM ' . $table . ' ORDER BY type, folder, element') as $row) {
                $manifest = json_decode((string) $row['manifest_cache'], true);
                $manifest = is_array($manifest) ? $manifest : [];
                if ((string) $row['type'] === 'file' && (string) $row['element'] === 'joomla' && is_string($manifest['version'] ?? null)) {
                    $extras['versionInDatabase'] = $manifest['version'];
                }
                if (preg_match('/^Joomla!?\s+Project$/i', is_string($manifest['author'] ?? null) ? trim($manifest['author']) : '') === 1) {
                    ++$core;
                    continue;
                }
                $third[] = [
                    'type' => (string) $row['type'],
                    'element' => (string) $row['element'],
                    'folder' => (string) $row['folder'],
                    'client' => (int) $row['client_id'] === 1 ? 'administrator' : 'site',
                    'name' => self::string($manifest['name'] ?? null),
                    'version' => self::string($manifest['version'] ?? null),
                    'author' => self::string($manifest['author'] ?? null),
                    'enabled' => (int) $row['enabled'] === 1,
                ];
            }
            $extras['coreExtensions'] = $core;
            $extras['extensions'] = $third;

            $languages = [];
            foreach ($db->select('SELECT lang_code, title, published FROM ' . $db->quote($db->table('languages')) . ' ORDER BY lang_code') as $row) {
                $languages[] = ['code' => (string) $row['lang_code'], 'title' => (string) $row['title'], 'published' => (int) $row['published'] === 1];
            }
            $extras['languages'] = $languages;

            $templates = [];
            foreach ($db->select('SELECT client_id, template, home, title FROM ' . $db->quote($db->table('template_styles')) . ' ORDER BY client_id, id') as $row) {
                $templates[] = [
                    'client' => (int) $row['client_id'] === 1 ? 'administrator' : 'site',
                    'template' => (string) $row['template'],
                    'title' => (string) $row['title'],
                    'default' => (string) $row['home'] === '1',
                ];
            }
            $extras['templates'] = $templates;

            $extras['addressCandidates'] = ($this->candidates ?? new AddressCandidates())->find($db);
        } catch (DatabaseError $e) {
            $extras['extrasWarning'] = 'Could not read the site data from the database: ' . $e->getMessage();
        }

        return $extras;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
