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
        foreach ([PHP_BINDIR . '/php', PHP_BINDIR . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, PHP_BINDIR . '/php.exe'] as $candidate) {
            if (@is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function __construct()
    {
    }
}
