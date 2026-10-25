<?php

declare(strict_types=1);

namespace ThreadSafe\Install;

/**
 * Where the home of the installation can go (docs/PHASE7.md 3.2): next to the document root where PHP can write there, so that nothing
 * of the program can be fetched at all; below it, closed by an `.htaccess`, where it cannot. The installer proposes the first and says
 * plainly what the second is. The functions look at the file system and change nothing.
 */
final class Places
{
    public const string OUTSIDE = 'threadsafe';

    public const string INSIDE = 'threadsafe-home';

    /**
     * The two places the installer proposes.
     *
     * @return list<array{path: string, inside: bool, usable: bool, reason: string}>
     */
    public static function candidates(string $docroot): array
    {
        $docroot = rtrim(str_replace('\\', '/', $docroot), '/');
        $places = [];
        foreach ([[dirname($docroot) . '/' . self::OUTSIDE, false], [$docroot . '/' . self::INSIDE, true]] as [$path, $inside]) {
            $problem = self::problem($path, $docroot);
            $places[] = ['path' => $path, 'inside' => $inside, 'usable' => $problem === null, 'reason' => $problem ?? ''];
        }

        return $places;
    }

    /**
     * Why a directory cannot be the home, or null if it can: it must be an absolute path, be new or empty, and be a directory PHP can make
     * or write in. It must not be the document root itself or lie in the installer's own state directory.
     */
    public static function problem(string $path, string $docroot): ?string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $docroot = rtrim(str_replace('\\', '/', $docroot), '/');
        if ($path === '' || (!str_starts_with($path, '/') && preg_match('#^[A-Za-z]:/#', $path) !== 1) || str_contains($path, "\0") || in_array('..', explode('/', $path), true)) {
            return 'The home must be a full path without "..".';
        }
        if ($path === $docroot || self::isBelow($docroot, $path)) {
            return 'The home cannot be the document root or a directory above it that holds it.';
        }
        if (self::isBelow($path, $docroot . '/.threadsafe-install')) {
            return 'The home cannot be inside the installer\'s own directory.';
        }
        if (file_exists($path) && !is_dir($path)) {
            return "\"$path\" exists and is not a directory.";
        }
        if (is_dir($path)) {
            if ((new \FilesystemIterator($path))->valid()) {
                return "\"$path\" exists and is not empty.";
            }

            return is_writable($path) ? null : "\"$path\" is not writable for PHP.";
        }
        $parent = dirname($path);
        while (!file_exists($parent) && dirname($parent) !== $parent) {
            $parent = dirname($parent);
        }
        if (file_exists($parent) && !is_dir($parent)) {
            return "PHP cannot make \"$path\": \"$parent\" is a file.";
        }

        return is_dir($parent) && is_writable($parent) ? null : "PHP cannot make \"$path\": \"$parent\" is not writable.";
    }

    /**
     * Why the document root cannot take the stub, or null if it can. A file of the same name is never overwritten.
     */
    public static function documentRootProblem(string $docroot): ?string
    {
        $docroot = rtrim(str_replace('\\', '/', $docroot), '/');
        if (!is_dir($docroot) || !is_writable($docroot)) {
            return "The directory \"$docroot\" is not writable for PHP; the installer cannot put index.php there.";
        }
        foreach (['index.php', '.htaccess'] as $name) {
            if (file_exists($docroot . '/' . $name)) {
                return "\"$name\" exists in \"$docroot\" already. The installer does not overwrite it: move it away (or install into an empty directory) and check again.";
            }
        }

        return null;
    }

    /** Whether $path is $directory or lies below it. */
    public static function isBelow(string $path, string $directory): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/') . '/';
        $directory = rtrim(str_replace('\\', '/', $directory), '/') . '/';

        return str_starts_with($path, $directory);
    }

    /** Whether the home lies below the document root, where only an `.htaccess` keeps it from being fetched. */
    public static function isInside(string $home, string $docroot): bool
    {
        return self::isBelow($home, $docroot);
    }

    private function __construct()
    {
    }
}
