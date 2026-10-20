<?php

declare(strict_types=1);

namespace ThreadSafe\Plugin;

/**
 * The `plugin.json` of a plugin directory.
 *
 * ```json
 * {
 *     "id": "prestashop9",
 *     "title": "PrestaShop 9",
 *     "version": "0.1.0",
 *     "api": 1,
 *     "class": "ThreadSafe\\PrestaShop9\\PrestaShop9Type",
 *     "autoload": {"ThreadSafe\\PrestaShop9\\": "src/"}
 * }
 * ```
 */
final readonly class PluginManifest
{
    /** The plugin interface version the program offers; a plugin must name exactly this. */
    public const int API = 1;

    private const array KEYS = ['id', 'title', 'version', 'api', 'class', 'autoload'];

    /**
     * @param array<string, string> $autoload namespace prefix (with trailing backslash) => directory (absolute)
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $version,
        public string $class,
        public array $autoload,
        public string $directory,
    ) {
    }

    /**
     * @throws PluginError
     */
    public static function fromDirectory(string $directory): self
    {
        $file = $directory . '/plugin.json';
        $content = is_file($file) ? file_get_contents($file) : false;
        if ($content === false) {
            throw new PluginError("Plugin \"$directory\" has no plugin.json.");
        }
        $fail = static fn (string $message): PluginError => new PluginError("plugin.json of \"" . basename($directory) . "\": $message");

        $data = json_decode($content, true);
        if (!is_array($data) || array_is_list($data)) {
            throw $fail('not a JSON object.');
        }
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw $fail("unknown key \"$key\".");
            }
        }

        $id = $data['id'] ?? null;
        if (!is_string($id) || preg_match('/^[a-z][a-z0-9_]*$/', $id) !== 1) {
            throw $fail('"id" must be lower-case letters, digits and underscores.');
        }
        if ($id !== basename($directory)) {
            throw $fail("\"id\" must be the name of the directory (\"" . basename($directory) . '").');
        }
        if (($data['api'] ?? null) !== self::API) {
            throw $fail('"api" must be ' . self::API . ' (the plugin interface of this program).');
        }
        $title = $data['title'] ?? $id;
        $version = $data['version'] ?? '0.0.0';
        $class = $data['class'] ?? null;
        if (!is_string($title) || !is_string($version) || !is_string($class) || preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $class) !== 1) {
            throw $fail('"title" and "version" must be strings and "class" a class name.');
        }

        $autoload = [];
        $rawAutoload = $data['autoload'] ?? null;
        if (!is_array($rawAutoload) || $rawAutoload === []) {
            throw $fail('"autoload" must map a namespace to a directory.');
        }
        foreach ($rawAutoload as $prefix => $path) {
            if (!is_string($path) || preg_match('/^[A-Za-z0-9_\\\\]+\\\\$/', (string) $prefix) !== 1
                || preg_match('~^[A-Za-z0-9_./-]+$~', $path) !== 1 || str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
                throw $fail('"autoload" entries need a namespace ending in a backslash and a relative directory inside the plugin.');
            }
            $autoload[(string) $prefix] = $directory . '/' . rtrim($path, '/');
        }

        return new self($id, $title, $version, $class, $autoload, $directory);
    }
}
