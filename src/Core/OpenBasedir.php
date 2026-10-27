<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

/**
 * PHP's `open_basedir`: on many shared hosts PHP may only touch a few directories, and every `file_exists()` or `is_dir()` on a path outside them
 * is a warning (which a host with `display_errors` on prints into the page, breaking its headers). Code that looks around the file system - the
 * installer asking where a home can go - asks here first and never touches a path that is not allowed.
 */
final class OpenBasedir
{
    /**
     * The directories PHP may use, without a trailing slash; empty if there is no restriction.
     *
     * @param string|null $setting the value of the setting; default: the one in force
     *
     * @return list<string>
     */
    public static function allowed(?string $setting = null): array
    {
        $setting = $setting ?? (string) ini_get('open_basedir');
        $directories = [];
        foreach (explode(PATH_SEPARATOR, $setting) as $directory) {
            $directory = rtrim(str_replace('\\', '/', trim($directory)), '/');
            if ($directory === '.') {
                $directory = rtrim(str_replace('\\', '/', (string) getcwd()), '/');
            }
            if ($directory !== '' && str_starts_with($directory, '/') || preg_match('#^[A-Za-z]:/#', $directory) === 1) {
                $directories[] = $directory;
            }
        }

        return $directories;
    }

    /**
     * Whether PHP may touch $path: there is no restriction, or the path is one of the allowed directories or below one.
     */
    public static function permits(string $path, ?string $setting = null): bool
    {
        $allowed = self::allowed($setting);
        if ($allowed === [] && trim($setting ?? (string) ini_get('open_basedir')) === '') {
            return true;
        }
        $path = rtrim(str_replace('\\', '/', $path), '/') . '/';
        foreach ($allowed as $directory) {
            if (str_starts_with($path, $directory . '/')) {
                return true;
            }
        }

        return false;
    }

    /** Whether there is a restriction at all. */
    public static function restricted(?string $setting = null): bool
    {
        return trim($setting ?? (string) ini_get('open_basedir')) !== '';
    }

    private function __construct()
    {
    }
}
