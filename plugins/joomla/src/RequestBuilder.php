<?php

declare(strict_types=1);

namespace ThreadSafe\Joomla;

use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Joomla\Config\JoomlaConfigFile;
use ThreadSafe\Joomla\Exception\InvalidConfigFile;
use ThreadSafe\Migration\RequestBuilder as BaseRequestBuilder;

/**
 * The request builder for Joomla: the common settings ({@see BaseRequestBuilder}). The database values that are not given come from
 * `configuration.php`; the old domain cannot be read from the installation (Joomla stores its own address nowhere as one value), so it
 * is given with `--from`. The settings of the adaptation itself come with work package 4 of phase 6.
 */
final class RequestBuilder extends BaseRequestBuilder
{
    protected function extraKeys(): array
    {
        return [];
    }

    protected function extraArguments(array $settings, string $root): array
    {
        return [];
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
