<?php

declare(strict_types=1);

namespace ThreadSafe\Profile;

use ThreadSafe\Core\OpenBasedir;

/**
 * The directory of the site that a profile points at, looked at before anything is read from it. "Not a readable directory" covers four different
 * things for the person who typed the path - a host that does not let PHP look there (`open_basedir`), a path that is
 * not there or is a file, a directory the user of PHP may not read - so the message says which one it is.
 */
final class SitePath
{
    /**
     * The real path of the directory.
     *
     * @throws ProfileException the path cannot be used; the message says why
     */
    public static function resolve(string $path, ?string $openBasedir = null): string
    {
        $problem = self::problem($path, $openBasedir);
        if ($problem !== null) {
            throw new ProfileException("The site path \"$path\" is not a readable directory: $problem");
        }
        $root = realpath($path);
        if ($root === false) {
            throw new ProfileException("The site path \"$path\" is not a readable directory: PHP cannot resolve it.");
        }

        return $root;
    }

    /**
     * Why the directory cannot be used, or null if it can. No path is touched that `open_basedir` does not allow (that would be a warning).
     */
    public static function problem(string $path, ?string $openBasedir = null): ?string
    {
        $normal = str_replace('\\', '/', $path);
        if ($normal === '' || str_contains($path, "\0")) {
            return 'the path is empty.';
        }
        $absolute = str_starts_with($normal, '/') || preg_match('#^[A-Za-z]:/#', $normal) === 1;
        if ($absolute && !OpenBasedir::permits($normal, $openBasedir)) {
            return 'this host lets PHP use only some directories (open_basedir: ' . implode(', ', OpenBasedir::allowed($openBasedir)) . ') and this is none of them or below one. '
                . 'ThreadSafe can only back up a site that lies in one of these; ask the host to add the site\'s directory to open_basedir of the virtual host that runs ThreadSafe.';
        }
        if (!@file_exists($path)) {
            return 'there is no such directory. Check the spelling, and that the path is the one PHP sees (on some hosts it differs from the one in the file manager).';
        }
        if (!@is_dir($path)) {
            return 'it is a file, not a directory.';
        }
        if (!@is_readable($path)) {
            return 'the user that PHP runs as may not read it. Give that user read access (the group or the permissions of the directory), or run ThreadSafe as the user that owns the site.';
        }

        return null;
    }

    private function __construct()
    {
    }
}
