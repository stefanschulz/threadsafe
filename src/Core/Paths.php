<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

/**
 * Where ThreadSafe's own files are.
 */
final class Paths
{
    /**
     * The program directory: the one with `src/`, `plugins/` and `bin/`. The single-file restore tool (docs/PHASE3.md 3.1) has no
     * such tree: it unpacks the plugins and their data files into a directory of its own and names it in the constant
     * THREADSAFE_PROGRAM_DIR before the code runs.
     */
    public static function program(): string
    {
        return defined('THREADSAFE_PROGRAM_DIR') ? rtrim((string) constant('THREADSAFE_PROGRAM_DIR'), '/\\') : dirname(__DIR__, 2);
    }

    /**
     * The home of an installation from a release (`<home>/releases/<version>` is the program directory, docs/PHASE7.md 3.2), or null for
     * an installation from a clone, where the program directory holds everything.
     */
    public static function home(): ?string
    {
        return Home::detect(self::program());
    }

    /**
     * The command line of the installation, the file that a cron job runs. In a home it is `<home>/bin/threadsafe`, which always runs the
     * active release, so that the cron job survives an update; a clone has `bin/threadsafe` in its program directory.
     */
    public static function cli(): string
    {
        $home = self::home();

        return $home !== null && is_file($home . '/bin/threadsafe') ? $home . '/bin/threadsafe' : self::program() . '/bin/threadsafe';
    }

    /** The directory with the website type plugins. */
    public static function plugins(): string
    {
        return self::program() . '/plugins';
    }

    /**
     * The data directory of the user's own files (site profiles, run states, locks, module rules ...); it need not exist. The
     * environment variable THREADSAFE_DATA moves it elsewhere (tests, or a data directory outside the program directory). In a home it
     * is `<home>/data`, shared by all releases; in a clone it is `data/` of the program directory.
     */
    public static function data(): string
    {
        $custom = getenv('THREADSAFE_DATA');

        if (is_string($custom) && $custom !== '') {
            return rtrim($custom, '/\\');
        }

        return (self::home() ?? self::program()) . '/data';
    }

    private function __construct()
    {
    }
}
