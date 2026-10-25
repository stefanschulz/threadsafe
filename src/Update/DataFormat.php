<?php

declare(strict_types=1);

namespace ThreadSafe\Update;

use ThreadSafe\Core\AtomicFile;

/**
 * `data/version.json`: which format the files of the data directory have and which program wrote them last (docs/PHASE7.md 3.6).
 * A release knows the format it writes ({@see \ThreadSafe\Core\Version::DATA_FORMAT}); the {@see Migrator} compares.
 */
final class DataFormat
{
    public const string FILE = 'version.json';

    /**
     * @return array{dataFormat: int, program: string}|null null if there is no such file or it cannot be read
     */
    public static function read(string $data): ?array
    {
        $content = @file_get_contents(rtrim($data, '/\\') . '/' . self::FILE);
        $json = is_string($content) ? json_decode($content, true) : null;
        if (!is_array($json) || !is_int($json['dataFormat'] ?? null) || $json['dataFormat'] < 1 || !is_string($json['program'] ?? null)) {
            return null;
        }

        return ['dataFormat' => $json['dataFormat'], 'program' => $json['program']];
    }

    public static function write(string $data, int $format, string $program): void
    {
        AtomicFile::write(rtrim($data, '/\\') . '/' . self::FILE, json_encode(['dataFormat' => $format, 'program' => $program], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    }

    private function __construct()
    {
    }
}
