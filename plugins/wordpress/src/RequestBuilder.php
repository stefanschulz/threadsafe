<?php

declare(strict_types=1);

namespace ThreadSafe\WordPress;

use InvalidArgumentException;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\Resources;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\ConnectionFailed;
use ThreadSafe\Database\DatabaseError;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\Migration\Mode;
use ThreadSafe\Migration\RequestBuilder as BaseRequestBuilder;
use ThreadSafe\WordPress\Config\WordPressConfigFile;
use ThreadSafe\WordPress\Exception\InvalidConfigFile;

/**
 * The request builder for WordPress: the common settings ({@see BaseRequestBuilder}) plus `path`, `mode` (production or dev), `keepDropIns`
 * and `includeGuid` (docs/MIGRATE.md section 14). The site must be at the root of its old domain: a path that changes is not something a
 * replacement of hosts can do.
 *
 * The database values that are not given come from `wp-config.php`; the old address is what the database says (`home`), so `--to` alone is
 * enough. The content replacement skips the tables of sessions and logs that a backup keeps empty anyway.
 */
final class RequestBuilder extends BaseRequestBuilder
{
    /** The column that holds the identifier of a post, which looks like an address and is not one. */
    public const string GUID = 'posts.guid';

    public function build(array $settings): MigrationRequest
    {
        $tables = $settings['skipTables'] ?? [];
        if (is_array($tables)) {
            $settings['skipTables'] = array_values(array_unique([...$tables, ...Resources::strings('wordpress/backup-defaults.json', 'structureOnlyTables')]));
        }
        $columns = $settings['skipColumns'] ?? [];
        if (is_array($columns) && ($settings['includeGuid'] ?? false) !== true) {
            $settings['skipColumns'] = array_values(array_unique([...$columns, self::GUID]));
        }

        return parent::build($settings);
    }

    protected function extraKeys(): array
    {
        return ['path', 'mode', 'keepDropIns', 'includeGuid'];
    }

    protected function extraArguments(array $settings, string $root): array
    {
        $mode = Mode::tryFrom($this->string($settings, 'mode') ?? 'production') ?? throw new InvalidArgumentException('The mode must be "production" or "dev".');
        $base = $this->string($settings, 'path');
        if ($base !== null) {
            $base = '/' . trim($base, '/') . '/';
            $base = $base === '//' ? '/' : $base;
        }

        return [
            'physicalUri' => $base,
            'mode' => $mode,
            'options' => ['keepDropIns' => $this->boolean($settings, 'keepDropIns', false)],
        ];
    }

    protected function domainsFollowBasePath(): bool
    {
        return true;
    }

    protected function configuredDatabase(string $root): ?DatabaseSettings
    {
        $found = Installation::configuration($root);
        if ($found === null) {
            return null;
        }
        try {
            return WordPressConfigFile::fromPath($found['path'])->database();
        } catch (InvalidConfigFile) {
            return null; // all values must then be given
        }
    }

    protected function defaultPrefix(): string
    {
        return ''; // the prefix comes from wp-config.php or is given
    }

    /**
     * The address the site has now: the option `home` of its database, as a host with its port.
     *
     * @throws GuardFailed
     */
    protected function currentDomain(?DatabaseSettings $database, string $root): string
    {
        $database ??= $this->configuredDatabase($root);
        if ($database === null) {
            throw new GuardFailed('The old domain cannot be read from the site (no database settings): give it with --from.');
        }
        try {
            $db = Connection::open($database);
            $home = $db->scalar('SELECT option_value FROM ' . $db->quote($db->table('options')) . " WHERE option_name = 'home'");
        } catch (ConnectionFailed | DatabaseError $e) {
            throw new GuardFailed('The old domain cannot be read from the database (' . $e->getMessage() . '): give it with --from.', 0, $e);
        }
        $parts = is_string($home) ? parse_url($home) : false;
        if (!is_array($parts) || !isset($parts['host'])) {
            throw new GuardFailed('The option "home" of the site is not an address: give the old domain with --from.');
        }

        return strtolower($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
    }
}
