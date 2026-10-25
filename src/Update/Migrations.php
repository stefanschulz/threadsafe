<?php

declare(strict_types=1);

namespace ThreadSafe\Update;

/**
 * The migrations of this release, one class per format step, in the order of the formats. Format 1 is the data of phases 2 to 6; there is
 * nothing to migrate to yet. A release that changes a file of `data/` adds its step here and raises {@see \ThreadSafe\Core\Version::DATA_FORMAT}.
 */
final class Migrations
{
    /**
     * @return list<Migration>
     */
    public static function all(): array
    {
        return [];
    }

    private function __construct()
    {
    }
}
