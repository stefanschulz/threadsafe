<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

/**
 * Version information of ThreadSafe.
 */
final class Version
{
    /** Semantic version; replaced by the release process. */
    public const string CURRENT = '0.0.0-dev';

    /** The version of the format of the files in `data/` that this release writes (docs/PHASE7.md 3.6); format 1 is the one of phases 2 to 6. */
    public const int DATA_FORMAT = 1;

    private function __construct()
    {
    }

    /** Whether the given string is a valid semantic version (with optional pre-release suffix). */
    public static function isValid(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/D', $version) === 1;
    }
}
