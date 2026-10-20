<?php

declare(strict_types=1);

namespace ThreadSafe\Archive;

/**
 * Which files are not worth compressing: formats that are compressed already.
 */
final class Compression
{
    private const array STORED = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'zip', 'gz', 'tgz', 'bz2', 'xz', 'zst', '7z', 'rar', 'lz', 'lzma',
        'pdf', 'mp3', 'mp4', 'm4a', 'm4v', 'mov', 'mkv', 'avi', 'webm', 'ogg', 'ogv', 'flac', 'woff', 'woff2', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'jar', 'apk',
    ];

    /** True if the file should be stored as it is (its name says it is compressed already). */
    public static function shouldStore(string $name): bool
    {
        $dot = strrpos($name, '.');

        return $dot !== false && in_array(strtolower(substr($name, $dot + 1)), self::STORED, true);
    }

    private function __construct()
    {
    }
}
