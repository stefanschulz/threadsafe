<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

/**
 * Where ThreadSafe's own files are.
 */
final class Paths
{
    /** The program directory: the one with `src/`, `plugins/` and `bin/`. */
    public static function program(): string
    {
        return dirname(__DIR__, 2);
    }

    /** The directory with the website type plugins. */
    public static function plugins(): string
    {
        return self::program() . '/plugins';
    }

    /**
     * The data directory of the user's own files (site profiles, run states, locks, module rules ...); it need not exist. The
     * environment variable THREADSAFE_DATA moves it elsewhere (tests, or a data directory outside the program directory).
     */
    public static function data(): string
    {
        $custom = getenv('THREADSAFE_DATA');

        return is_string($custom) && $custom !== '' ? rtrim($custom, '/\\') : self::program() . '/data';
    }

    private function __construct()
    {
    }
}
