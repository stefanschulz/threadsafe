<?php

declare(strict_types=1);

namespace ThreadSafe\Joomla;

use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Joomla\Config\JoomlaConfigFile;
use ThreadSafe\Joomla\Exception\InvalidConfigFile;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\Migration\RequestBuilder as BaseRequestBuilder;

/**
 * The request builder for Joomla: the common settings ({@see BaseRequestBuilder}) plus
 *
 *   path   string   new base path, e.g. "/sub/" (the directory of the domain the site lives in); default: unchanged
 *
 * The database values that are not given come from `configuration.php`. The old domain cannot be read from the installation (Joomla
 * stores its own address nowhere as one value): it is given with `--from`, and a restore proposes one from its manifest.
 *
 * The content replacement skips what Joomla rebuilds and what a backup keeps empty anyway (the sessions, the update cache and the big
 * tables of the search index), so that a migration of a copy that still has them does not spend its time there.
 */
final class RequestBuilder extends BaseRequestBuilder
{
    /** @var list<string> tables (without prefix) that the replacement of the old address leaves alone */
    public const array SKIPPED_TABLES = [
        'session', 'user_keys', 'updates',
        'finder_links', 'finder_links_terms', 'finder_logging', 'finder_taxonomy_map', 'finder_terms', 'finder_tokens', 'finder_tokens_aggregate',
    ];

    public function build(array $settings): MigrationRequest
    {
        $given = $settings['skipTables'] ?? [];
        if (is_array($given)) {
            $settings['skipTables'] = array_values(array_unique([...$given, ...self::SKIPPED_TABLES]));
        }

        return parent::build($settings);
    }

    protected function extraKeys(): array
    {
        return ['path'];
    }

    protected function extraArguments(array $settings, string $root): array
    {
        return ['physicalUri' => $this->string($settings, 'path')];
    }

    protected function configuredDatabase(string $root): ?DatabaseSettings
    {
        try {
            return JoomlaConfigFile::fromPath(Installation::configurationPath($root))->database();
        } catch (InvalidConfigFile) {
            return null; // all values must then be given
        }
    }

    protected function defaultPrefix(): string
    {
        return ''; // the prefix of a Joomla site is random; it comes from configuration.php or is given
    }

    protected function currentDomain(?DatabaseSettings $database, string $root): string
    {
        throw new GuardFailed('A Joomla site does not say which domain it uses: give the old domain with --from.');
    }
}
