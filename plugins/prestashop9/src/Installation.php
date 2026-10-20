<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9;

/**
 * What can be read from the files of a PrestaShop installation without starting it.
 */
final class Installation
{
    /** The version from `src/Core/Version.php`, or null if the directory holds no PrestaShop 8/9. */
    public static function version(string $root): ?string
    {
        $file = rtrim($root, '/\\') . '/src/Core/Version.php';
        $content = is_file($file) ? @file_get_contents($file) : false;
        if ($content === false || preg_match("/const\\s+VERSION\\s*=\\s*'([^']+)'/", $content, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /** The back office folder has an arbitrary name; it is the directory that contains themes/new-theme. */
    public static function adminDirectory(string $root): ?string
    {
        $root = rtrim($root, '/\\');
        foreach (@scandir($root) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_dir($root . '/' . $entry . '/themes/new-theme')) {
                return $entry;
            }
        }

        return null;
    }

    private function __construct()
    {
    }
}
