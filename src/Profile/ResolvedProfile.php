<?php

declare(strict_types=1);

namespace ThreadSafe\Profile;

use ThreadSafe\Backup\Filters;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Plugin\Backupable;
use ThreadSafe\Plugin\DetectedSite;

/**
 * A profile together with what the website type found out about the site: the detected application, the database
 * settings to use, and the filters that apply (defaults of the type plus the profile's own, plus the paths that must
 * never be part of a backup).
 */
final readonly class ResolvedProfile
{
    /**
     * @param string       $root            the site path with symbolic links resolved
     * @param list<string> $excludePaths    patterns, defaults first
     * @param list<string> $structureOnly   table names (with or without prefix)
     * @param list<string> $literalExcludes paths inside the site that are excluded exactly: the storage, the program's data
     */
    public function __construct(
        public SiteProfile $profile,
        public Backupable $type,
        public DetectedSite $site,
        public string $root,
        public ?DatabaseSettings $database,
        public array $excludePaths,
        public array $structureOnly,
        public array $literalExcludes,
    ) {
    }

    public function filters(): Filters
    {
        return new Filters($this->excludePaths, $this->literalExcludes);
    }
}
