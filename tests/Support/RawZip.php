<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

/**
 * Builds ZIP archives byte by byte, with every field overridable, so that tests can make archives no sane writer would
 * produce: wrong CRCs and sizes, hostile names, overlapping entries, cut-off structures.
 *
 * Each entry is an array with at least `name`; optional keys: `data` (content), `method` (8 deflated, 0 stored),
 * `flags`, `crc`, `compressed`, `size` (override the true values in the central directory), `raw` (the bytes stored as
 * data instead of the processed content), `localName`, `extra`, `centralExtra`, `external`, `offset`.
 */
final class RawZip
{
    /**
     * @param list<array<string, mixed>> $entries
     * @param array<string, mixed>       $options `comment`, `entries` (count announced), `disk` (disk number in the end record)
     */
    public static function build(array $entries, array $options = []): string
    {
        $body = '';
        $directory = '';
        foreach ($entries as $entry) {
            $name = (string) $entry['name'];
            $content = (string) ($entry['data'] ?? '');
            $method = (int) ($entry['method'] ?? 8);
            $packed = (string) ($entry['raw'] ?? ($method === 8 ? gzdeflate($content, 6) : $content));
            $crc = (int) ($entry['crc'] ?? crc32($content));
            $compressed = (int) ($entry['compressed'] ?? strlen($packed));
            $size = (int) ($entry['size'] ?? strlen($content));
            $flags = (int) ($entry['flags'] ?? 0);
            $offset = strlen($body);

            $localName = (string) ($entry['localName'] ?? $name);
            $extra = (string) ($entry['extra'] ?? '');
            $body .= pack('VvvvvvVVVvv', 0x04034b50, 20, $flags, $method, 0, 0x21, $crc, strlen($packed), strlen($content), strlen($localName), strlen($extra)) . $localName . $extra . $packed;

            $centralExtra = (string) ($entry['centralExtra'] ?? '');
            $directory .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                (3 << 8) | 20,
                20,
                $flags,
                $method,
                0,
                0x21,
                $crc,
                $compressed,
                $size,
                strlen($name),
                strlen($centralExtra),
                0,
                0,
                0,
                (int) ($entry['external'] ?? (0100644 << 16)),
                (int) ($entry['offset'] ?? $offset),
            ) . $name . $centralExtra;
        }

        $comment = (string) ($options['comment'] ?? '');
        $count = (int) ($options['entries'] ?? count($entries));
        $end = pack('VvvvvVVv', 0x06054b50, (int) ($options['disk'] ?? 0), 0, $count, $count, strlen($directory), strlen($body), strlen($comment)) . $comment;

        return $body . $directory . $end;
    }

    /** An ZIP64 extra field with the given 64-bit values. */
    public static function zip64Extra(int ...$values): string
    {
        return pack('vv', 0x0001, 8 * count($values)) . pack('P*', ...$values);
    }

    private function __construct()
    {
    }
}
