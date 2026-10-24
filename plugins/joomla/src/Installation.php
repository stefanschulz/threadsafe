<?php

declare(strict_types=1);

namespace ThreadSafe\Joomla;

use ThreadSafe\Database\ServerInfo;

/**
 * What the files of a directory say about a Joomla installation. Reads only; nothing is included or run.
 */
final class Installation
{
    /** The versions this type knows (docs/PHASE6.md 7.2, decision 1). */
    public const array MAJOR_VERSIONS = [5, 6];

    /**
     * The version from `libraries/src/Version.php` (`public const MAJOR_VERSION = 5;` ...), or null if the directory has none, for
     * example a Joomla 3 (the file is elsewhere) or not Joomla at all.
     */
    public static function version(string $root): ?string
    {
        $file = rtrim($root, '/\\') . '/libraries/src/Version.php';
        $content = is_file($file) ? @file_get_contents($file) : false;
        if ($content === false) {
            return null;
        }
        $parts = [];
        foreach (['MAJOR_VERSION', 'MINOR_VERSION', 'PATCH_VERSION'] as $name) {
            if (preg_match('/const\s+' . $name . '\s*=\s*(\d+)\s*;/', $content, $m) !== 1) {
                return null;
            }
            $parts[] = $m[1];
        }
        $version = implode('.', $parts);
        if (preg_match("/const\\s+EXTRA_VERSION\\s*=\\s*'([^']+)'\\s*;/", $content, $m) === 1) {
            $version .= '-' . $m[1];
        }

        return $version;
    }

    /** The installed site has its `configuration.php` (before the installation it is missing) and the folder of the administration. */
    public static function isInstalled(string $root): bool
    {
        $root = rtrim($root, '/\\');

        return is_file($root . '/configuration.php') && is_dir($root . '/administrator');
    }

    /**
     * What the database server must be for this Joomla, as a sentence if it is not: Joomla 6 needs MariaDB 10.4 or MySQL 8.0.13 (from the
     * project's requirements; a warning, not a refusal, because ThreadSafe cannot know every server's version string). Joomla 5 is
     * content with what ThreadSafe itself needs.
     */
    public static function databaseProblem(int $major, ServerInfo $server): ?string
    {
        if ($major < 6 || $server->version === '') {
            return null;
        }
        $minimum = match ($server->type) {
            ServerInfo::MARIADB => '10.4.0',
            ServerInfo::MYSQL => '8.0.13',
            default => null,
        };
        if ($minimum === null || version_compare($server->version, $minimum, '>=')) {
            return null;
        }

        return sprintf('Joomla 6 needs %s %s or newer; this server is %s %s.', $server->type === ServerInfo::MARIADB ? 'MariaDB' : 'MySQL', $minimum, $server->type === ServerInfo::MARIADB ? 'MariaDB' : 'MySQL', $server->version);
    }

    public static function configurationPath(string $root): string
    {
        return rtrim($root, '/\\') . '/configuration.php';
    }

    private function __construct()
    {
    }
}
