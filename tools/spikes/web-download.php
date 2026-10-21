<?php

/**
 * SPIKE (phase 4, WP 1a) - throw-away script, not part of the program. Copy it into a web root next to a big file named
 * spike-big.bin and request it:
 *
 *   web-download.php?mode=stream    set_time_limit(0), 1 MB blocks, flush, abort check   (the planned technique)
 *   web-download.php?mode=nolimit   the same without set_time_limit(0) (is a slow download cut by max_execution_time?)
 *   web-download.php?mode=readfile  readfile() for comparison
 *
 * It understands one Range (bytes=a-b, a-, -n). Every request appends a line to spike-log.txt.
 */
declare(strict_types=1);

$mode = $_GET['mode'] ?? 'stream';
$file = __DIR__ . '/spike-big.bin';
$log = static function (string $m): void {
    file_put_contents(__DIR__ . '/spike-log.txt', date('H:i:s') . ' ' . $m . "\n", FILE_APPEND);
};
$size = (int) filesize($file);
$start = 0;
$end = $size - 1;
$status = 200;
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'], $m) === 1 && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') {
        $start = max(0, $size - (int) $m[2]);
    } else {
        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
    }
    if ($start > $end || $start >= $size) {
        header('HTTP/1.1 416 Range Not Satisfiable');
        header('Content-Range: bytes */' . $size);
        exit;
    }
    $status = 206;
}
$length = $end - $start + 1;

while (ob_get_level() > 0) {
    ob_end_clean();
}
ini_set('zlib.output_compression', '0');
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
}
if ($mode !== 'nolimit') {
    set_time_limit(0);
}
ignore_user_abort(true);

http_response_code($status);
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="spike.zip"');
header('Accept-Ranges: bytes');
header('Content-Length: ' . $length);
if ($status === 206) {
    header("Content-Range: bytes $start-$end/$size");
}

$t = microtime(true);
$sent = 0;
$how = 'finished';
if ($mode === 'readfile') {
    $sent = (int) readfile($file);
} else {
    $in = fopen($file, 'rb');
    fseek($in, $start);
    $left = $length;
    while ($left > 0) {
        $block = fread($in, min(1048576, $left));
        if ($block === false || $block === '') {
            $how = 'read failed';
            break;
        }
        echo $block;
        flush();
        $left -= strlen($block);
        $sent += strlen($block);
        if (connection_aborted()) {
            $how = 'client gone';
            break;
        }
    }
    fclose($in);
}
$log(sprintf('%s %s range=%s status=%d sent=%d of %d in %.1f s, %s, peak memory %.1f MB', $mode, $how, $_SERVER['HTTP_RANGE'] ?? '-', $status, $sent, $length, microtime(true) - $t, connection_aborted() ? 'aborted' : 'ok', memory_get_peak_usage(true) / 1048576));
