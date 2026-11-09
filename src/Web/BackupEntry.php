<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

use ThreadSafe\Cli\Application;
use ThreadSafe\Cli\CommandLine;
use ThreadSafe\Cli\StreamOutput;
use ThreadSafe\Cli\ToolAgentCommand;
use ThreadSafe\Cli\ToolBackupCommand;
use ThreadSafe\Engine\Budget;

/**
 * What the single-file `threadsafe-backup.php` does once its code is loaded (docs/PHASE12.md 3.2, 3.7): on a web server it is the wizard; in a shell
 * (`php threadsafe-backup.php backup ...`) it is the command {@see ToolBackupCommand}, which needs no key because shell access is trusted already.
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
            if (PHP_SAPI !== 'cli') {
                // the PHP CGI binary has a time limit of its own and prints HTML errors, which a shell does not want
                @set_time_limit(0);
                @ini_set('html_errors', '0');
            }
            // no $_SERVER['argv'] if register_argc_argv is off (the CGI binary with php.ini-production): the process knows its command line
            $arguments = CommandLine::arguments($_SERVER['argv'] ?? null, $script) ?? [$script];

            return (new Application([new ToolBackupCommand(dirname($script), basename($script)), new ToolAgentCommand(dirname($script), basename($script))]))->run($arguments, StreamOutput::standard());
        }

        // a warning of PHP (a path that open_basedir does not allow, say) must never reach the page: it would end the headers
        @ini_set('display_errors', '0');
        $workspace = self::workspace($script, defined('THREADSAFE_WORKSPACE'), defined('THREADSAFE_WORKSPACE') ? constant('THREADSAFE_WORKSPACE') : null);
        if (is_string($workspace)) {
            // a path that is not good is not guessed at: the page says that the tool is set up wrongly and the log says why
            @error_log('ThreadSafe backup tool: THREADSAFE_WORKSPACE cannot be used: ' . $workspace);
            Response::html('<!doctype html><meta charset="utf-8"><title>Not set up</title><p>This tool is set up wrongly. The error log of the host says why.</p>', 500)->send();

            return 1;
        }
        if (isset($_GET['api']) && is_string($_GET['api'])) {
            // a program, not a person: the agent (docs/PHASE13.md 3.1), which answers a stranger like a file that is not there
            (new Agent($workspace ?? BackupWorkspace::in(dirname($script))))->handle(Request::fromGlobals())->send();

            return 0;
        }
        (new BackupWizard(dirname($script), null, basename($script), null, null, $workspace))->handle(Request::fromGlobals())->send();

        return 0;
    }

    /**
     * The workspace the tool uses: next to the tool, or where a plugin put it (the constant THREADSAFE_WORKSPACE, defined before the tool is loaded;
     * docs/PHASE13.md 9.2).
     *
     * @param bool  $defined whether the constant THREADSAFE_WORKSPACE is defined
     * @param mixed $value   its value
     *
     * @return BackupWorkspace|string|null null: next to the tool; a workspace; or, as a text, why the path cannot be used
     */
    public static function workspace(string $script, bool $defined, mixed $value): BackupWorkspace|string|null
    {
        if (!$defined) {
            return null;
        }
        $problem = is_string($value) ? BackupWorkspace::customProblem($value, dirname($script)) : 'it is not a text';

        return $problem ?? BackupWorkspace::at((string) $value);
    }

    private function __construct()
    {
    }
}
