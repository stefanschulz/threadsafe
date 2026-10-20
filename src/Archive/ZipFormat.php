<?php

declare(strict_types=1);

namespace ThreadSafe\Archive;

/**
 * The byte layout of ZIP records, as pure functions: no files, no state. Used by {@see ZipWriter} and
 * {@see ZipReader}; the tests feed it values above 4 GiB that no test archive could contain.
 *
 * Choices: UTF-8 names (flag bit 11) when a name is not ASCII; MS-DOS time and date in UTC (they have no zone of their
 * own) plus the "extended timestamp" field with the exact Unix time; Unix permissions in the external attributes.
 */
final class ZipFormat
{
    public const int LOCAL_HEADER = 0x04034b50;

    public const int CENTRAL_HEADER = 0x02014b50;

    public const int END_RECORD = 0x06054b50;

    public const int END_RECORD64 = 0x06064b50;

    public const int END_LOCATOR64 = 0x07064b50;

    public const int DATA_DESCRIPTOR = 0x08074b50;

    public const int FLAG_ENCRYPTED = 0x0001;

    public const int FLAG_DESCRIPTOR = 0x0008;

    public const int FLAG_UTF8 = 0x0800;

    public const int METHOD_STORE = 0;

    public const int METHOD_DEFLATE = 8;

    public const int MAX_32 = 0xFFFFFFFF;

    public const int MAX_16 = 0xFFFF;

    public const int EXTRA_ZIP64 = 0x0001;

    public const int EXTRA_TIMESTAMP = 0x5455;

    /** What deflate can expand at most (1032:1), with some room: bigger claims are not believed. */
    public const int MAX_EXPANSION = 1100;

    private const int HOST_UNIX = 3;

    private const int VERSION = 45;

    /**
     * Why a name must not be extracted, or null if it is fine: absolute paths, drive letters, `..`, empty segments,
     * backslashes and NUL would let an archive write outside the target directory (Zip Slip) or confuse other systems.
     */
    public static function unsafeReason(string $name): ?string
    {
        if ($name === '' || strlen($name) > self::MAX_16) {
            return 'empty or too long name';
        }
        if (str_contains($name, "\0")) {
            return 'name contains NUL';
        }
        if (str_contains($name, '\\')) {
            return 'name contains a backslash';
        }
        if (str_starts_with($name, '/')) {
            return 'absolute path';
        }
        if (preg_match('/^[A-Za-z]:/', $name) === 1) {
            return 'drive letter';
        }
        $segments = explode('/', rtrim($name, '/'));
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return 'path leaves its directory or has empty segments';
            }
        }

        return null;
    }

    /**
     * [time, date] in MS-DOS format for a Unix time, in UTC, limited to 1980-2107.
     *
     * @return array{int, int}
     */
    public static function dosDateTime(int $unix): array
    {
        $unix = max(315532800, min($unix, 4354819198)); // 1980-01-01 00:00:00 .. 2107-12-31 23:59:58
        [$year, $month, $day, $hour, $minute, $second] = array_map('intval', explode('-', gmdate('Y-n-j-G-i-s', $unix)));

        return [($hour << 11) | ($minute << 5) | intdiv($second, 2), (($year - 1980) << 9) | ($month << 5) | $day];
    }

    public static function unixFromDos(int $time, int $date): int
    {
        $second = ($time & 0x1F) * 2;
        $minute = ($time >> 5) & 0x3F;
        $hour = ($time >> 11) & 0x1F;
        $day = max(1, $date & 0x1F);
        $month = max(1, min(12, ($date >> 5) & 0x0F));
        $year = 1980 + (($date >> 9) & 0x7F);

        return (int) gmmktime($hour, $minute, $second, $month, $day, $year);
    }

    /** The extended timestamp field (modification time, UTC). */
    public static function timestampExtra(int $mtime): string
    {
        return pack('vvCV', self::EXTRA_TIMESTAMP, 5, 1, max(0, min($mtime, 0x7FFFFFFF)));
    }

    public static function localHeader(string $name, int $flags, int $method, int $mtime, bool $zip64, int $crc = 0, int $compressed = 0, int $size = 0): string
    {
        [$time, $date] = self::dosDateTime($mtime);
        $extra = self::timestampExtra($mtime);
        if ($zip64) {
            // With a data descriptor the sizes come later; the field announces that they will be 8 bytes wide.
            $extra .= pack('vvPP', self::EXTRA_ZIP64, 16, $size, $compressed);
        }
        $descriptor = ($flags & self::FLAG_DESCRIPTOR) !== 0;

        return pack(
            'VvvvvvVVVvv',
            self::LOCAL_HEADER,
            $zip64 ? self::VERSION : 20,
            $flags,
            $method,
            $time,
            $date,
            $descriptor ? 0 : $crc,
            $zip64 ? self::MAX_32 : ($descriptor ? 0 : $compressed),
            $zip64 ? self::MAX_32 : ($descriptor ? 0 : $size),
            strlen($name),
            strlen($extra),
        ) . $name . $extra;
    }

    public static function dataDescriptor(int $crc, int $compressed, int $size, bool $zip64): string
    {
        return pack('VV', self::DATA_DESCRIPTOR, $crc) . ($zip64 ? pack('PP', $compressed, $size) : pack('VV', $compressed, $size));
    }

    /**
     * The central directory record of an entry. A value that does not fit 32 bits is written as 0xFFFFFFFF and given in
     * the ZIP64 field instead.
     */
    public static function centralRecord(string $name, int $flags, int $method, int $mtime, int $mode, bool $directory, int $crc, int $compressed, int $size, int $offset, bool $zip64): string
    {
        [$time, $date] = self::dosDateTime($mtime);
        $big = [
            'size' => $size >= self::MAX_32,
            'compressed' => $compressed >= self::MAX_32,
            'offset' => $offset >= self::MAX_32,
        ];
        $extra = self::timestampExtra($mtime);
        $values = '';
        if ($big['size']) {
            $values .= pack('P', $size);
        }
        if ($big['compressed']) {
            $values .= pack('P', $compressed);
        }
        if ($big['offset']) {
            $values .= pack('P', $offset);
        }
        if ($values !== '') {
            $extra .= pack('vv', self::EXTRA_ZIP64, strlen($values)) . $values;
        }
        $needs64 = $zip64 || $values !== '';

        return pack(
            'VvvvvvvVVVvvvvvVV',
            self::CENTRAL_HEADER,
            (self::HOST_UNIX << 8) | self::VERSION,
            $needs64 ? self::VERSION : 20,
            $flags,
            $method,
            $time,
            $date,
            $crc,
            $big['compressed'] ? self::MAX_32 : $compressed,
            $big['size'] ? self::MAX_32 : $size,
            strlen($name),
            strlen($extra),
            0,
            0,
            0,
            (($mode & 0xFFFF) << 16) | ($directory ? 0x10 : 0),
            $big['offset'] ? self::MAX_32 : $offset,
        ) . $name . $extra;
    }

    /**
     * The records that end a part: the ZIP64 end record and its locator when needed (or forced), then the classic end record.
     */
    public static function endRecords(int $entries, int $directorySize, int $directoryOffset, bool $force64): string
    {
        $needs64 = $force64 || $entries >= self::MAX_16 || $directorySize >= self::MAX_32 || $directoryOffset >= self::MAX_32;
        $out = '';
        if ($needs64) {
            $out .= pack('VPvvVVPPPP', self::END_RECORD64, 44, (self::HOST_UNIX << 8) | self::VERSION, self::VERSION, 0, 0, $entries, $entries, $directorySize, $directoryOffset);
            $out .= pack('VVPV', self::END_LOCATOR64, 0, $directoryOffset + $directorySize, 1);
        }

        return $out . pack(
            'VvvvvVVv',
            self::END_RECORD,
            0,
            0,
            $needs64 ? self::MAX_16 : $entries,
            $needs64 ? self::MAX_16 : $entries,
            $needs64 ? self::MAX_32 : $directorySize,
            $needs64 ? self::MAX_32 : $directoryOffset,
            0,
        );
    }

    /**
     * The fields of an extra block by id (a field that is cut off is ignored).
     *
     * @return array<int, string>
     */
    public static function parseExtra(string $extra): array
    {
        $fields = [];
        $position = 0;
        $length = strlen($extra);
        while ($position + 4 <= $length) {
            $parsed = unpack('vid/vsize', $extra, $position);
            if ($parsed === false) {
                break;
            }
            $position += 4;
            if ($position + $parsed['size'] > $length) {
                break;
            }
            $fields[$parsed['id']] ??= substr($extra, $position, $parsed['size']);
            $position += $parsed['size'];
        }

        return $fields;
    }

    private function __construct()
    {
    }
}
