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
 * Read cron-lifetime.log afterwards. Delete both files when you are done.
 */

$seconds = isset($argv[1]) ? (int) $argv[1] : 300;
$log = dirname(__FILE__) . '/cron-lifetime.log';

$lock = fopen(dirname(__FILE__) . '/cron-lifetime.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);   // an earlier run is still alive: this is what the real trigger does as well
}

function note($log, $text)
{
    file_put_contents($log, date('Y-m-d H:i:s') . '  ' . $text . "\n", FILE_APPEND);
}

$started = time();
$stdin = function_exists('posix_isatty') ? (@posix_isatty(STDIN) ? 'a terminal' : 'not a terminal') : 'unknown';
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
    // a little work as well, in case the host measures CPU time and not the wall clock
    $x = 0;
    for ($i = 0; $i < 2000000; ++$i) {
        $x += $i % 7;
    }
    note($log, 'alive for ' . (time() - $started) . ' s');
}
note($log, 'done after ' . (time() - $started) . ' s - nothing cut this process off');
