<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9;

use ThreadSafe\Core\Step;
use ThreadSafe\PrestaShop9\Steps\ApplyDevMode;
use ThreadSafe\PrestaShop9\Steps\BlockSearchEngines;
use ThreadSafe\PrestaShop9\Steps\DiscardCaches;
use ThreadSafe\PrestaShop9\Steps\EnsureSkeleton;
use ThreadSafe\PrestaShop9\Steps\Finalize;
use ThreadSafe\PrestaShop9\Steps\ReadSourceState;
use ThreadSafe\PrestaShop9\Steps\ReplaceContentDomains;
use ThreadSafe\PrestaShop9\Steps\RewriteHostBoundFiles;
use ThreadSafe\PrestaShop9\Steps\RewriteHtaccess;
use ThreadSafe\PrestaShop9\Steps\SelfTest;
use ThreadSafe\PrestaShop9\Steps\UpdateConfiguration;
use ThreadSafe\PrestaShop9\Steps\WriteParameters;

/**
 * The steps of a PrestaShop 9 migration in the order of docs/CONCEPT.md 4.2. The two dev mode steps are always in the
 * list and do nothing in production mode. Finalize (PrestaShop clears its own caches) and the self-test come last and
 * run on every applied run.
 *
 * The dev mode database step runs before the caches are discarded (the compiled container lists the active modules);
 * the dev mode file step runs after the host-bound files (it replaces the robots.txt they have just fixed).
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
            new ApplyDevMode(),
            new DiscardCaches(),
            new UpdateConfiguration(),
            new ReplaceContentDomains(),
            new RewriteHtaccess(),
            new RewriteHostBoundFiles(),
            new BlockSearchEngines(),
            new EnsureSkeleton(),
            new Finalize(),
            new SelfTest(),
        ];
    }

    private function __construct()
    {
    }
}
