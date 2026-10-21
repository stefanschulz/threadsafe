<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

use RuntimeException;

/**
 * The directory the restore keeps its state in (sessions, the run, the journal, the unpacked SQL files): mode 0700, closed to
 * the web server by an `.htaccess` (Apache) and an empty index page, so that nothing in it can be fetched even if the host
 * ignores the first.
 */
final class StateDirectory
{
    public const string NAME = '.threadsafe-restore';

    /**
     * @throws RuntimeException the directory cannot be made
     */
    public static function prepare(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create the directory \"$directory\" for the state of the restore.");
        }
        @chmod($directory, 0700);
        $htaccess = $directory . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "# Nothing in here is for the web.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
        }
        if (!is_file($directory . '/index.html')) {
            @file_put_contents($directory . '/index.html', '');
        }
    }
}
