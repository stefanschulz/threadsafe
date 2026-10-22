<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use Closure;
use ThreadSafe\Web\Request;
use ThreadSafe\Web\Response;

/**
 * Sends a file to the browser (docs/PHASE4.md 3.6; the technique was measured in spike 1a): `Content-Length`, `Accept-Ranges`, a
 * single range with `206` so that a broken download can be resumed, `If-Range` against the ETag or the date of the file (a web
 * server or a rule in front may strip the ETag; the date survives), blocks of 1 MB, no output
 * buffering and no compression, no time limit, and the loop stops when the client has gone. A web server that can send files by
 * itself can be asked to ({@see $accel}): it then also does the ranges.
 */
final class Download
{
    private const int BLOCK = 1048576;

    /**
     * @param string                                           $path        the file
     * @param string                                           $filename    the name the browser saves it under (letters, digits, dots, hyphens, underscores)
     * @param string                                           $etag        what identifies this content (the SHA-256 of the file)
     * @param string                                           $accel       "none", "sendfile" (X-Sendfile) or "nginx" (X-Accel-Redirect)
     * @param string                                           $accelPrefix for "nginx": the internal location; the full path of the file is appended
     * @param Closure(string, array<string, scalar|null>): void $report      told when the sending starts and ends (event, details)
     * @param string                                           $contentType the media type of the file
     */
    public static function response(Request $request, string $path, string $filename, string $etag, string $accel = 'none', string $accelPrefix = '', ?Closure $report = null, string $contentType = 'application/zip'): Response
    {
        $size = is_file($path) ? (int) filesize($path) : -1;
        if ($size < 0 || preg_match('/^[A-Za-z0-9._-]+$/', $filename) !== 1) {
            return new Response(404, ['Content-Type: text/plain; charset=utf-8'], "Not found.\n");
        }

        $modified = gmdate('D, d M Y H:i:s', (int) filemtime($path)) . ' GMT';
        $headers = [
            'Content-Type: ' . $contentType,
            'Content-Disposition: attachment; filename="' . $filename . '"',
            'Accept-Ranges: bytes',
            'ETag: "' . $etag . '"',
            'Last-Modified: ' . $modified,
            'Cache-Control: private, no-transform',
        ];

        if ($accel === 'sendfile') {
            return new Response(200, [...$headers, 'X-Sendfile: ' . $path], '');
        }
        if ($accel === 'nginx' && $accelPrefix !== '') {
            return new Response(200, [...$headers, 'X-Accel-Redirect: ' . $accelPrefix . ltrim($path, '/')], '');
        }

        $range = ByteRange::parse($request->header('range'), $size);
        $ifRange = $request->header('if-range');
        if ($ifRange !== null && trim($ifRange) !== '"' . $etag . '"' && trim($ifRange) !== $modified) {
            $range = null;   // the file is not the one the browser started: it must have all of it
        }
        if ($range === false) {
            return new Response(416, ['Content-Range: bytes */' . $size, 'Content-Type: text/plain; charset=utf-8'], "Range not satisfiable.\n");
        }

        [$first, $last] = $range ?? [0, max(0, $size - 1)];
        $length = $size === 0 ? 0 : $last - $first + 1;
        $headers[] = 'Content-Length: ' . $length;
        if ($range !== null) {
            $headers[] = "Content-Range: bytes $first-$last/$size";
        }
        $status = $range !== null ? 206 : 200;

        if ($request->method === 'HEAD' || $length === 0) {
            return new Response($status, $headers, '');
        }

        return new Response($status, $headers, '', static function () use ($path, $first, $length, $report, $filename): void {
            self::send($path, $first, $length, $report, $filename);
        });
    }

    /**
     * @param (Closure(string, array<string, scalar|null>): void)|null $report
     */
    private static function send(string $path, int $first, int $length, ?Closure $report, string $filename): void
    {
        // a download runs for as long as the connection does; the time limit counts CPU time on Linux, but not everywhere
        @set_time_limit(0);
        @ignore_user_abort(true);
        @ini_set('zlib.output_compression', '0');
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        // (not on the command line, where a test collects the output in a buffer of its own)
        while (PHP_SAPI !== 'cli' && ob_get_level() > 0) {
            @ob_end_clean();
        }

        $in = @fopen($path, 'rb');
        if ($in === false) {
            return;
        }
        if ($report !== null) {
            $report('download.start', ['file' => $filename, 'from' => $first, 'bytes' => $length]);
        }
        fseek($in, $first);
        $left = $length;
        $sent = 0;
        $aborted = false;
        while ($left > 0) {
            $block = fread($in, min(self::BLOCK, $left));
            if ($block === false || $block === '') {
                break;
            }
            echo $block;
            flush();
            $left -= strlen($block);
            $sent += strlen($block);
            if (connection_aborted()) {
                $aborted = true;
                break;
            }
        }
        fclose($in);
        if ($report !== null) {
            $report('download.end', ['file' => $filename, 'from' => $first, 'sent' => $sent, 'complete' => !$aborted && $left === 0]);
        }
    }

    private function __construct()
    {
    }
}
