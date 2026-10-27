<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

/**
 * The PHP command line program that can run a script as a child process. Under Apache's mod_php `PHP_BINARY` is empty, under
 * PHP-FPM or CGI it is the server's own program (running it with a script would start a server), so only a program that is
 * the command line version, or `php` in the directory PHP was installed to, counts.
 */
final class PhpBinary
{
    /**
     * @return string|null null if there is none, or if this PHP may not start processes (`disable_functions`)
     */
    public static function cli(): ?string
    {
        if (!function_exists('proc_open')) {
            return null;
        }
        if (PHP_SAPI === 'cli') {
            return PHP_BINARY;
        }
        // the program named for this version first: on a host with several PHP versions `php` may be another one
        foreach ([PHP_BINDIR . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, PHP_BINDIR . '/php', PHP_BINDIR . '/php.exe'] as $candidate) {
            if (@is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * What the check says when there is no command line PHP: what is missing, what still works, and what to do. One text for every place that checks.
     */
    public static function missingNote(): string
    {
        return 'Not found by the PHP of the web interface: it may not start a process (the host blocks proc_open), or the host has no php program next to the one that runs the web. '
            . 'This matters only for the trigger address, which then works in small steps (cron.mode "ticks"), has to be called every minute or so, and makes backups that are consistent table by table, not as a whole. '
            . 'A cron job that runs bin/threadsafe cron itself is not affected: it uses the command line PHP of the system, has no time limit and makes consistent snapshots, which is the better way for scheduled backups. '
            . 'Backups, checks, downloads and updates from the web interface work as before. To change it for the web, ask the host to allow proc_open and where its PHP command line program is.';
    }

    private function __construct()
    {
    }
}
