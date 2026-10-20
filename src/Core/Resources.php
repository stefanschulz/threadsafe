<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

use RuntimeException;

/**
 * Access to the data files a plugin ships (`plugins/<id>/resources/`): lists such as the tables to skip. A resource
 * path starts with the plugin id: "prestashop9/skip-tables.json" is `plugins/prestashop9/resources/skip-tables.json`.
 */
final class Resources
{
    /**
     * @return array<string, mixed>
     */
    public static function json(string $relativePath): array
    {
        $path = self::path($relativePath);
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

    /** The absolute path of a resource of a plugin. */
    public static function path(string $relativePath): string
    {
        $parts = explode('/', $relativePath, 2);
        if (count($parts) !== 2 || preg_match('/^[a-z0-9_-]+$/', $parts[0]) !== 1 || in_array('..', explode('/', $parts[1]), true)) {
            throw new RuntimeException("Resource path \"$relativePath\" must be <plugin id>/<file> inside the plugin.");
        }

        return Paths::plugins() . '/' . $parts[0] . '/resources/' . $parts[1];
    }
}
