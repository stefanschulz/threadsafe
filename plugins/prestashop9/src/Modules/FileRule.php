<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9\Modules;

/**
 * Files of a module that a migration treats specially.
 */
final readonly class FileRule
{
    /** Files that belong to the old environment and are rebuilt by the module: removed with the caches. */
    public const string DELETE = 'delete';

    /** Text files that contain the domain: it is replaced like in the other host-bound files. */
    public const string REPLACE = 'replace';

    /**
     * @param string $glob   relative to the shop root, `*`, `?` and `[...]` as in glob()
     * @param string $action delete | replace
     */
    public function __construct(public string $glob, public string $action)
    {
    }
}
