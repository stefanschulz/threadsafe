<?php

declare(strict_types=1);

namespace ThreadSafe\Install;

use ThreadSafe\Web\Request;

/**
 * What the single-file `install.php` does once its code is loaded: on a web server it is the installer wizard. In a shell it says what
 * to do instead: the file is a ZIP archive, and `unzip` unpacks the package.
 */
final class Entry
{
    /**
     * @param string $script absolute path of `install.php`; the directory it is in is the document root the stub goes into
     *
     * @return int the exit code (only meaningful in a shell)
     */
    public static function run(string $script): int
    {
        if (!isset($_SERVER['REQUEST_METHOD'])) {
            file_put_contents('php://stderr', "This is the web installer of ThreadSafe: open it in a browser (after putting install-key.php next to it).\n"
                . "The file is also a ZIP archive with the program; \"unzip " . basename($script) . "\" unpacks it for an installation by hand.\n");

            return 1;
        }
        (new Wizard($script))->handle(Request::fromGlobals())->send();

        return 0;
    }

    private function __construct()
    {
    }
}
