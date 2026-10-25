<?php

declare(strict_types=1);

namespace ThreadSafe\App;

use ThreadSafe\Core\Paths;
use ThreadSafe\Core\PhpBinary;

/**
 * The lines a person puts into a crontab (or the settings of a cron service) to call the trigger, with the real paths of this
 * installation. Shared by `admin cron-secret` and the System page.
 */
final class TriggerHelp
{
    /** The crontab line for the command; `-q` makes cron mail only problems. */
    public static function commandLine(string $schedule = '*/5 * * * *'): string
    {
        $php = PhpBinary::cli() ?? 'php';

        return $schedule . ' ' . $php . ' -q ' . Paths::cli() . ' cron -q';
    }

    /** The crontab line for an address, for a host whose cron can only fetch one. */
    public static function addressLine(string $address, string $schedule = '*/5 * * * *'): string
    {
        return $schedule . ' curl -fsS -m 60 "' . $address . '" > /dev/null';
    }

    /** The path of the trigger below the directory of `index.php`. */
    public static function path(string $secret): string
    {
        return '/cron/' . $secret;
    }

    private function __construct()
    {
    }
}
