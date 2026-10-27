<?php

declare(strict_types=1);

namespace ThreadSafe\Install;

use ThreadSafe\Core\OpenBasedir;

/**
 * Where the home of the installation can go (docs/PHASE7.md 3.2): next to the document root where PHP can write there, so that nothing of the program
 * can be fetched at all; in a directory that the host's `open_basedir` allows outside the document root (a "private" directory is common); below the
 * document root, closed by an `.htaccess`, where it cannot. The installer proposes them in that order and says plainly what the last one is. The
 * functions look at the file system and change nothing; they never touch a path that `open_basedir` does not allow (a warning would be printed into
 * the page of a host with `display_errors` on).
 */
final class Places
{
    public const string OUTSIDE = 'threadsafe';

    public const string INSIDE = 'threadsafe-home';

    /** Directories that `open_basedir` often allows and that are no place for a program: temporary, system and cache directories. */
    private const array NOT_A_HOME = ['/bin', '/usr', '/opt', '/etc', '/lib', '/var/lib', '/proc', '/dev'];

    /** The temporary directories themselves (a directory below them, such as a test's, is not excluded). */
    private const array TEMPORARY = ['/tmp', '/var/tmp'];

    private const array NOT_A_HOME_NAMES = ['tmp', 'temp', 'cache', 'sessions', 'logs', 'log'];

    /**
     * The places the installer proposes.
     *
     * `kind` is `beside` (next to the document root), `allowed` (a directory that `open_basedir` allows, outside the document root) or `inside` (below
     * the document root).
     *
     * @return list<array{id: string, kind: string, path: string, inside: bool, usable: bool, reason: string}>
     */
    public static function candidates(string $docroot, ?string $openBasedir = null): array
    {
        $docroot = rtrim(str_replace('\\', '/', $docroot), '/');
        $wanted = [['outside', 'beside', dirname($docroot) . '/' . self::OUTSIDE]];
        foreach (self::allowedHomes($docroot, $openBasedir) as $index => $path) {
            $wanted[] = ['allowed-' . ($index + 1), 'allowed', $path . '/' . self::OUTSIDE];
        }
        $wanted[] = ['inside', 'inside', $docroot . '/' . self::INSIDE];

        $places = [];
        foreach ($wanted as [$id, $kind, $path]) {
            $problem = self::problem($path, $docroot, $openBasedir);
            $places[] = ['id' => $id, 'kind' => $kind, 'path' => $path, 'inside' => $kind === 'inside', 'usable' => $problem === null, 'reason' => $problem ?? ''];
        }

        return $places;
    }

    /**
     * The directories `open_basedir` allows that could hold a home: writable, outside the document root, not hidden, not temporary or system directories.
     * Empty without a restriction (then the directory next to the document root is the answer).
     *
     * @return list<string>
     */
    public static function allowedHomes(string $docroot, ?string $openBasedir = null): array
    {
        $docroot = rtrim(str_replace('\\', '/', $docroot), '/');
        $homes = [];
        foreach (OpenBasedir::allowed($openBasedir) as $directory) {
            $name = basename($directory);
            if (str_starts_with($name, '.') || in_array(strtolower($name), self::NOT_A_HOME_NAMES, true) || in_array($directory, [...self::TEMPORARY, rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/')], true)) {
                continue;
            }
            foreach (self::NOT_A_HOME as $system) {
                if (self::isBelow($directory, $system)) {
                    continue 2;
                }
            }
            if (self::isBelow($directory, $docroot) || self::isBelow($docroot, $directory) || !@is_dir($directory) || !@is_writable($directory)) {
                continue;
            }
            $homes[] = $directory;
        }

        return array_slice($homes, 0, 3);
    }

    /**
     * Why a directory cannot be the home, or null if it can: it must be an absolute path, be new or empty, and be a directory PHP may use and can make
     * or write in. It must not be the document root itself or lie in the installer's own state directory.
     */
    public static function problem(string $path, string $docroot, ?string $openBasedir = null): ?string
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
        if (!OpenBasedir::permits($path, $openBasedir)) {
            return "\"$path\" is outside the directories that this host lets PHP use (open_basedir: " . implode(', ', OpenBasedir::allowed($openBasedir)) . ').';
        }
        if (@file_exists($path) && !@is_dir($path)) {
            return "\"$path\" exists and is not a directory.";
        }
        if (@is_dir($path)) {
            if ((new \FilesystemIterator($path))->valid()) {
                return "\"$path\" exists and is not empty.";
            }

            return @is_writable($path) ? null : "\"$path\" is not writable for PHP.";
        }
        $parent = dirname($path);
        while (!@file_exists($parent) && dirname($parent) !== $parent && OpenBasedir::permits(dirname($parent), $openBasedir)) {
            $parent = dirname($parent);
        }
        if (!OpenBasedir::permits($parent, $openBasedir)) {
            return "PHP cannot make \"$path\": \"$parent\" is outside the directories that this host lets PHP use.";
        }
        if (@file_exists($parent) && !@is_dir($parent)) {
            return "PHP cannot make \"$path\": \"$parent\" is a file.";
        }

        return @is_dir($parent) && @is_writable($parent) ? null : "PHP cannot make \"$path\": \"$parent\" is not writable.";
    }

    /**
     * Why the document root cannot take the stub, or null if it can. A file of the same name is never overwritten.
     */
    public static function documentRootProblem(string $docroot): ?string
    {
        $docroot = rtrim(str_replace('\\', '/', $docroot), '/');
        if (!@is_dir($docroot) || !@is_writable($docroot)) {
            return "The directory \"$docroot\" is not writable for PHP; the installer cannot put index.php there.";
        }
        foreach (['index.php', '.htaccess'] as $name) {
            if (@file_exists($docroot . '/' . $name)) {
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
