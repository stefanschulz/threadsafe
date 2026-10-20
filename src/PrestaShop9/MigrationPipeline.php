<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9;

use ThreadSafe\Core\Step;
use ThreadSafe\PrestaShop9\Steps\DiscardCaches;
use ThreadSafe\PrestaShop9\Steps\EnsureSkeleton;
use ThreadSafe\PrestaShop9\Steps\ReadSourceState;
use ThreadSafe\PrestaShop9\Steps\ReplaceContentDomains;
use ThreadSafe\PrestaShop9\Steps\RewriteHostBoundFiles;
use ThreadSafe\PrestaShop9\Steps\RewriteHtaccess;
use ThreadSafe\PrestaShop9\Steps\UpdateConfiguration;
use ThreadSafe\PrestaShop9\Steps\WriteParameters;

/**
 * The steps of a PrestaShop 9 migration in the order of docs/CONCEPT.md 4.2. Steps for the dev mode, the optional
 * boot of PrestaShop and the self-test are added by later work packages.
 */
final class MigrationPipeline
{
    /**
     * @return list<Step>
     */
    public static function steps(): array
    {
        return [
            new ReadSourceState(),
            new WriteParameters(),
            new DiscardCaches(),
            new UpdateConfiguration(),
            new ReplaceContentDomains(),
            new RewriteHtaccess(),
            new RewriteHostBoundFiles(),
            new EnsureSkeleton(),
        ];
    }

    private function __construct()
    {
    }
}
