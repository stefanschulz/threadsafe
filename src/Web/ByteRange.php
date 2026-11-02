<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

/**
 * The `Range` header of a download request (RFC 9110, section 14): which bytes of a file of a known size are wanted.
 *
 * Only a single range of bytes is served. Anything else - another unit, several ranges, a header that does not parse - is ignored,
 * as the standard allows, and the whole file is sent. A range that starts behind the end of the file cannot be satisfied.
 */
final class ByteRange
{
    /**
     * @return array{int, int}|null|false first and last byte (inclusive); null = send everything; false = not satisfiable (416)
     */
    public static function parse(?string $header, int $size): array|null|false
    {
        if ($header === null || preg_match('/^\s*bytes\s*=\s*(\d*)\s*-\s*(\d*)\s*$/i', $header, $m) !== 1 || ($m[1] === '' && $m[2] === '')) {
            return null;
        }
        if ($size <= 0) {
            return false;
        }

        if ($m[1] === '') {
            // the last n bytes
            $n = (int) $m[2];
            if ($n === 0 || strlen($m[2]) > 18) {
                return $n === 0 ? false : [0, $size - 1];
            }

            return [max(0, $size - $n), $size - 1];
        }
        if (strlen($m[1]) > 18) {
            return false;
        }
        $first = (int) $m[1];
        if ($first >= $size) {
            return false;
        }
        if ($m[2] === '') {
            return [$first, $size - 1];
        }
        $last = strlen($m[2]) > 18 ? $size - 1 : (int) $m[2];
        if ($last < $first) {
            return null;   // not a valid range: ignored
        }

        return [$first, min($last, $size - 1)];
    }

    private function __construct()
    {
    }
}
