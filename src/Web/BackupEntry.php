<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

use ThreadSafe\Engine\Budget;

/**
 * What the single-file `threadsafe-backup.php` does once its code is loaded (docs/PHASE12.md 3.2, 3.7). **A placeholder until work packages 4 to 6:** the file is
 * built and loads, and says that the wizard is not there yet.
 */
final class BackupEntry
{
    /**
     * @param string $script absolute path of the tool
     *
     * @return int the exit code (only meaningful in a shell)
     */
    public static function run(string $script): int
    {
        $message = 'This is the ThreadSafe backup tool, and it is not finished: the wizard comes with phase 12 (docs/PHASE12.md).';
        if (Budget::isCommandLine()) {
            fwrite(STDERR, $message . "\n");

            return 1;
        }
        @ini_set('display_errors', '0');
        (new Response(501, ['Content-Type: text/plain; charset=utf-8', 'Cache-Control: no-store'], $message . "\n"))->send();

        return 0;
    }

    private function __construct()
    {
    }
}
