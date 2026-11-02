<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

use ThreadSafe\Engine\Budget;

/**
 * What the single-file `threadsafe-backup.php` does once its code is loaded (docs/PHASE12.md 3.2, 3.7): on a web server it is the wizard. **The shell
 * mode is a placeholder until work package 6**, and the wizard stops at the summary until work package 5: the file is built and loads.
 */
final class BackupEntry
{
    /**
     * @param string $script absolute path of the tool; the directory it is in is where the key file and, by default, the site are
     *
     * @return int the exit code (only meaningful in a shell)
     */
    public static function run(string $script): int
    {
        if (Budget::isCommandLine()) {
            fwrite(STDERR, "This is the ThreadSafe backup tool. Its shell mode is not finished: the wizard needs a web server (docs/PHASE12.md).\n");

            return 1;
        }

        // a warning of PHP (a path that open_basedir does not allow, say) must never reach the page: it would end the headers
        @ini_set('display_errors', '0');
        (new BackupWizard(dirname($script), null, basename($script)))->handle(Request::fromGlobals())->send();

        return 0;
    }

    private function __construct()
    {
    }
}
