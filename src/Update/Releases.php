<?php

declare(strict_types=1);

namespace ThreadSafe\Update;

use ThreadSafe\Core\Home;
use ThreadSafe\Web\Cleanup;

/**
 * The releases a home holds (`<home>/releases/<version>`): which there are, and the removal of the ones that are no longer needed. The
 * active release and the previous one stay (docs/PHASE7.md 3.5, decision 5); the rest, and every half-unpacked `.partial`, go.
 */
final class Releases
{
    /**
     * @return list<string> the names of the directories in `releases/`, sorted
     */
    public static function all(string $home): array
    {
        $names = [];
        foreach (scandir(rtrim($home, '/\\') . '/releases') ?: [] as $name) {
            if ($name !== '.' && $name !== '..' && is_dir(rtrim($home, '/\\') . '/releases/' . $name)) {
                $names[] = $name;
            }
        }
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Removes every directory of `releases/` that is not one of $keep.
     *
     * @param list<string> $keep release names
     *
     * @return list<string> what could not be removed, as messages
     */
    public static function prune(string $home, array $keep): array
    {
        $problems = [];
        foreach (self::all($home) as $name) {
            if (!in_array($name, $keep, true)) {
                array_push($problems, ...Cleanup::run([], rtrim($home, '/\\') . '/releases/' . $name));
            }
        }

        return $problems;
    }

    /** Removes one release directory, or a half-unpacked one (`<version>.partial`). */
    public static function remove(string $home, string $name): void
    {
        if ($name !== '' && !str_contains($name, '/') && !str_contains($name, '\\') && $name !== '.' && $name !== '..') {
            Cleanup::run([], rtrim($home, '/\\') . '/releases/' . $name);
        }
    }

    /** Whether a release is unpacked and complete enough to be made active. */
    public static function usable(string $home, string $release): bool
    {
        return is_file(Home::releaseDirectory($home, $release) . '/vendor/autoload.php');
    }

    private function __construct()
    {
    }
}
