<?php

declare(strict_types=1);

namespace ThreadSafe\Generic;

use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Migration\RequestBuilder as BaseRequestBuilder;

/**
 * The request builder for "any website": only the common settings. Nothing can be read from the installation, so the
 * old domain (`from`) and, if there is a database, all its values must be given.
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
        return null;
    }

    protected function defaultPrefix(): string
    {
        return ''; // a generic site has no table prefix convention
    }

    protected function currentDomain(?DatabaseSettings $database, string $root): string
    {
        throw new GuardFailed('A generic site does not say which domain it uses: give the old domain with --from.');
    }
}
