<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

use RuntimeException;

/**
 * Access to the data files shipped with ThreadSafe (`resources/`): lists such as the tables to skip.
 */
final class Resources
{
    /**
     * @return array<string, mixed>
     */
    public static function json(string $relativePath): array
    {
        $path = dirname(__DIR__, 2) . '/resources/' . $relativePath;
        $content = is_file($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw new RuntimeException("Resource \"$relativePath\" not found.");
        }
        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new RuntimeException("Resource \"$relativePath\" is not valid JSON.");
        }

        return $data;
    }

    /**
     * A list of strings stored under $key.
     *
     * @return list<string>
     */
    public static function strings(string $relativePath, string $key): array
    {
        $list = self::json($relativePath)[$key] ?? null;
        if (!is_array($list)) {
            throw new RuntimeException("Resource \"$relativePath\" has no list \"$key\".");
        }

        return array_values(array_map('strval', $list));
    }
}
