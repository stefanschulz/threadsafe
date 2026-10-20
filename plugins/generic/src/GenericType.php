<?php

declare(strict_types=1);

namespace ThreadSafe\Generic;

use ThreadSafe\Cli\Input;
use ThreadSafe\Generic\Steps\ReadGenericState;
use ThreadSafe\Generic\Steps\ReplaceContentDomains;
use ThreadSafe\Generic\Steps\SelfTest;
use ThreadSafe\Migration\RequestBuilder as BaseRequestBuilder;
use ThreadSafe\Plugin\WebsiteType;

/**
 * The website type "any website": a site ThreadSafe knows nothing about. It can do what needs no knowledge of the
 * application: replace the old domain in every text column of the database (serialized PHP and JSON stay valid) and
 * check that the migrated site answers. Everything else - configuration files, caches, web server rules - stays with
 * the owner of the site.
 */
final class GenericType implements WebsiteType
{
    public function id(): string
    {
        return 'generic';
    }

    public function title(): string
    {
        return 'Any website';
    }

    public function options(): array
    {
        return [];
    }

    public function settings(Input $input): array
    {
        return [];
    }

    public function requestBuilder(): BaseRequestBuilder
    {
        return new RequestBuilder();
    }

    public function steps(): array
    {
        return [
            new ReadGenericState(),
            new ReplaceContentDomains(),
            new SelfTest(),
        ];
    }
}
