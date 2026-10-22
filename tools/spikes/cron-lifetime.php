<?php
/**
 * SPIKE (phase 5, WP 1c) - how long may a process that a cron job starts live on a host, and what does it see?
 *
 * For the owner to run where backups will be made. It writes one line every 10 seconds to a log file next to itself and ends
 * after the time given (or when the host kills it: the last line then tells how long it lived). It does nothing else, prints no
 * secret and reads no settings.
 *
 * Like host-probe.php it is written for old PHP versions (no short arrays, no namespaces).
 *
 *   as a cron job, every minute is fine, the script takes a lock so that only one runs at a time:
 *       * * * * * /usr/bin/php8.4 -q /path/to/cron-lifetime.php 600
 *   by hand:
 *       php cron-lifetime.php 120
 *
 * For a host whose cron can only fetch an address (HTTP GET), put the file in the document root, set $WEB_TOKEN below and call
 *       cron-lifetime.php?token=...&mode=spawn&seconds=600    starts a detached background process (setsid, nohup, standard
 *                                                              input /dev/null) and answers at once; the log then shows whether
 *                                                              the host lets that process live after the request has ended
 *       cron-lifetime.php?token=...&mode=run&seconds=120      runs inside the request itself and answers with a line every 10 s:
 *                                                              the log shows where the host cuts a request off
 *
 * Read cron-lifetime.log afterwards. If the log has no "start:" line after a spawn, read cron-lifetime.out
 * (what the child printed). Delete the files (and the .log, .out, .lock, .spawned) when you are done.
 */

$WEB_TOKEN = '';   // web mode refuses to run while this is empty

// The CGI binary run from a shell reports a SAPI of "cgi-fcgi", not "cli" (as in src/Engine/Budget.php): it is a web request only
// when the web server has set REQUEST_METHOD.
$web = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== '';
$arguments = isset($argv) ? $argv : (isset($_SERVER['argv']) ? $_SERVER['argv'] : array());
$seconds = isset($arguments[1]) ? (int) $arguments[1] : 300;
$log = dirname(__FILE__) . '/cron-lifetime.log';

if ($web) {
    header('Content-Type: text/plain; charset=utf-8');
    if ($WEB_TOKEN === '' || !isset($_GET['token']) || $_GET['token'] !== $WEB_TOKEN) {
        header('HTTP/1.1 403 Forbidden');
        echo "Set \$WEB_TOKEN in this file and call it with ?token=...&mode=spawn or &mode=run\n";
        exit;
    }
    $seconds = isset($_GET['seconds']) ? (int) $_GET['seconds'] : 120;
    $mode = isset($_GET['mode']) ? $_GET['mode'] : '';
    if ($mode === 'spawn') {
        $binary = '';
        foreach (array('/usr/bin/php8.4', '/usr/local/bin/php8.4', PHP_BINARY) as $candidate) {
            if ($candidate !== '' && @is_executable($candidate)) {
                $binary = $candidate;
                break;
            }
        }
        if ($binary === '' || !function_exists('exec')) {
            echo "Cannot start a process from here: no PHP binary found, or exec is disabled.\n";
            exit;
        }
        // Safety net, because the first version of this script started itself over and over on a real host: a child that
        // inherits the environment of a CGI request (QUERY_STRING, REQUEST_METHOD ...) is run by the CGI binary as another
        // request, passes the token check and spawns again. Never more than one spawn per minute, and a clean environment.
        $marker = dirname(__FILE__) . '/cron-lifetime.spawned';
        if (@is_file($marker) && time() - (int) @filemtime($marker) < 60) {
            echo "Refused: a process was started less than a minute ago.\n";
            exit;
        }
        @touch($marker);
        $found = array();
        @exec('command -v setsid 2>/dev/null < /dev/null', $found);
        $command = 'env -i PATH=/usr/local/bin:/usr/bin:/bin ' . (isset($found[0]) && trim($found[0]) !== '' ? 'setsid ' : '') . 'nohup ' . escapeshellarg($binary) . ' -q ' . escapeshellarg(__FILE__) . ' ' . (int) $seconds . ' < /dev/null > ' . escapeshellarg(dirname(__FILE__) . '/cron-lifetime.out') . ' 2>&1 &';   // what the child prints is kept for the diagnosis
        file_put_contents($log, date('Y-m-d H:i:s') . '  web request: starts a detached process (' . ($found ? 'setsid, ' : '') . 'nohup, ' . $binary . ') for ' . (int) $seconds . " s\n", FILE_APPEND);
        @exec($command);
        echo "Started. This request ends now; read cron-lifetime.log in about " . (int) $seconds . " s.\n";
        exit;
    }
    if ($mode !== 'run') {
        echo "Add &mode=spawn or &mode=run (and &seconds=N).\n";
        exit;
    }
}

$lock = fopen(dirname(__FILE__) . '/cron-lifetime.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if ($web) {
        echo "An earlier run is still alive (read the log).\n";
    }
    exit(0);   // an earlier run is still alive: this is what the real trigger does as well
}

function note($log, $text)
{
    file_put_contents($log, date('Y-m-d H:i:s') . '  ' . $text . "\n", FILE_APPEND);
}

$started = time();
$stdin = (function_exists('posix_isatty') && defined('STDIN')) ? (@posix_isatty(STDIN) ? 'a terminal' : 'not a terminal') : 'unknown';
note($log, 'start: PHP ' . PHP_VERSION . ' (' . PHP_SAPI . '), binary ' . (defined('PHP_BINARY') ? PHP_BINARY : '?') . ', pid ' . (function_exists('getmypid') ? getmypid() : '?') . ', stdin ' . $stdin);
note($log, 'limits: max_execution_time ' . ini_get('max_execution_time') . ', memory_limit ' . ini_get('memory_limit') . ', user ' . (function_exists('get_current_user') ? get_current_user() : '?') . ', timezone ' . date_default_timezone_get());
note($log, 'environment: ' . (getenv('PATH') !== false ? 'PATH is set' : 'PATH is not set') . ', HOME ' . (getenv('HOME') !== false ? 'set' : 'not set') . ', ' . (isset($_SERVER['argv']) ? 'argv ok' : 'no argv'));
if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}
note($log, 'time limit after set_time_limit(0): ' . ini_get('max_execution_time'));

$memory = array();
while (time() - $started < $seconds) {
    sleep(10);
    if ($web) {
        echo 'alive for ' . (time() - $started) . " s\n";
        flush();
    }
    // a little work as well, in case the host measures CPU time and not the wall clock
    $x = 0;
    for ($i = 0; $i < 2000000; ++$i) {
        $x += $i % 7;
    }
    note($log, 'alive for ' . (time() - $started) . ' s');
}
note($log, 'done after ' . (time() - $started) . ' s - nothing cut this process off');
