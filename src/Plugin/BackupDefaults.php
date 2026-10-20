<?php

declare(strict_types=1);

namespace ThreadSafe\Plugin;

/**
 * What a website type leaves out of a backup unless told otherwise.
 */
final readonly class BackupDefaults
{
    /**
     * @param list<string> $excludePaths        patterns relative to the site root (see {@see \ThreadSafe\Backup\Filters})
     * @param list<string> $structureOnlyTables table names without prefix, of which only the structure is kept
     */
    public function __construct(public array $excludePaths = [], public array $structureOnlyTables = [])
    {
    }
}
