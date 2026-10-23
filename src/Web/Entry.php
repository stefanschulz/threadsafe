<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

use ThreadSafe\Cli\Application;
use ThreadSafe\Cli\CommandLine;
use ThreadSafe\Cli\RestoreCommand;
use ThreadSafe\Cli\StreamOutput;
use ThreadSafe\Engine\Budget;

/**
 * What the single-file `restore.php` does once its code is loaded: on a web server it is the wizard; in a shell
 * (`php restore.php restore <archive> --root=DIR ...`) it is the `restore` command, which needs no key because shell access is
 * trusted already (docs/PHASE3.md 3.10).
 */
final class Entry
{
    /**
     * @param string $script absolute path of the tool; the directory it is in is where the key file and, by default, the site are
     *
     * @return int the exit code (only meaningful in a shell)
     */
    public static function run(string $script): int
    {
        if (Budget::isCommandLine()) {
            if (PHP_SAPI !== 'cli') {
                // the PHP CGI binary has a time limit of its own and prints HTML errors, which a shell does not want
                @set_time_limit(0);
                @ini_set('html_errors', '0');
            }
            // no $_SERVER['argv'] if register_argc_argv is off (the CGI binary with php.ini-production): the process knows its command line
            $arguments = CommandLine::arguments($_SERVER['argv'] ?? null, $script) ?? [$script];

            return (new Application([new RestoreCommand()]))->run($arguments, StreamOutput::standard());
        }

        (new Wizard(dirname($script), null, basename($script)))->handle(Request::fromGlobals())->send();

        return 0;
    }

    private function __construct()
    {
    }
}
