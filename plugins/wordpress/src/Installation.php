<?php

declare(strict_types=1);

namespace ThreadSafe\WordPress;

use ThreadSafe\Core\OpenBasedir;

/**
 * What the files of a directory say about a WordPress installation. Reads only; nothing is included or run, and no path is touched that
 * `open_basedir` does not allow (the directory above the site is looked at only when it is allowed).
 */
final class Installation
{
    /** The oldest WordPress this type knows (docs/PHASE9.md 7.2, decision 3). */
    public const string MINIMUM_VERSION = '6.0';

    /** The files WordPress loads from `wp-content` under these exact names; a service that is not there breaks the site (finding 5). */
    public const array DROP_INS = ['advanced-cache.php', 'object-cache.php', 'db.php'];

    /** Where `wp-config.php` was found: in the site directory, or one directory above it. */
    public const string IN_ROOT = 'root';

    public const string ABOVE_ROOT = 'above';

    /**
     * The version from `wp-includes/version.php` (`$wp_version = '6.9.4';`), or null if the directory has none.
     */
    public static function version(string $root): ?string
    {
        return self::variable($root, 'wp_version');
    }

    /** `$wp_db_version` of the same file (the number of the database schema), or null. */
    public static function databaseVersion(string $root): ?string
    {
        return self::variable($root, 'wp_db_version');
    }

    /** Whether this version is one the type backs up and restores. */
    public static function isSupported(string $version): bool
    {
        return version_compare($version, self::MINIMUM_VERSION, '>=');
    }

    /**
     * The installed site: the files of WordPress and a `wp-config.php` (before the installation only `wp-config-sample.php` is there).
     */
    public static function isInstalled(string $root): bool
    {
        $root = rtrim($root, '/\\');

        return is_file($root . '/wp-load.php') && is_dir($root . '/wp-admin') && self::configuration($root) !== null;
    }

    /**
     * Where `wp-config.php` is, by the rule of WordPress: in the site directory; if it is not there and the directory above has none
     * of the files of WordPress (`wp-settings.php`), in the directory above (a common way to keep it out of the document root).
     *
     * @return array{path: string, location: string}|null
     */
    public static function configuration(string $root): ?array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (is_file($root . '/wp-config.php')) {
            return ['path' => $root . '/wp-config.php', 'location' => self::IN_ROOT];
        }
        $parent = dirname($root);
        if ($parent === $root || !OpenBasedir::permits($parent)) {
            return null;
        }
        if (is_file($parent . '/wp-config.php') && !is_file($parent . '/wp-settings.php')) {
            return ['path' => $parent . '/wp-config.php', 'location' => self::ABOVE_ROOT];
        }

        return null;
    }

    /**
     * The drop-ins that are present in `wp-content`: services of the old host that the new one may not have.
     *
     * @return list<string>
     */
    public static function dropIns(string $root): array
    {
        $content = rtrim($root, '/\\') . '/wp-content';

        return array_values(array_filter(self::DROP_INS, static fn (string $name): bool => is_file($content . '/' . $name)));
    }

    /**
     * The names of the must-use plugins (files and directories of `wp-content/mu-plugins`, without `index.php`).
     *
     * @return list<string>
     */
    public static function mustUsePlugins(string $root): array
    {
        $directory = rtrim($root, '/\\') . '/wp-content/mu-plugins';
        $names = [];
        foreach (is_dir($directory) ? (scandir($directory) ?: []) : [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && $entry !== 'index.php') {
                $names[] = $entry;
            }
        }

        return $names;
    }

    private static function variable(string $root, string $name): ?string
    {
        $file = rtrim($root, '/\\') . '/wp-includes/version.php';
        $content = is_file($file) ? @file_get_contents($file) : false;
        if ($content === false || preg_match('/\$' . $name . '\s*=\s*[\'"]?([0-9][0-9A-Za-z.\-]*)[\'"]?\s*;/', $content, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    private function __construct()
    {
    }
}
