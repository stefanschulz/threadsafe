<?php

declare(strict_types=1);

namespace ThreadSafe\App;

use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Plugin\DetectedSite;

/**
 * What a website type makes of a directory: the application, the database it reads from the application's own configuration (if
 * the type can), and how much the type leaves out of a backup by default. Shown to the person before a profile is stored.
 */
final readonly class Detection
{
    public function __construct(
        public DetectedSite $site,
        public string $root,
        public ?DatabaseSettings $database,
        public int $excludedPaths,
        public int $structureOnlyTables,
    ) {
    }
}
