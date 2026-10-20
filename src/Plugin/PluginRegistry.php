<?php

declare(strict_types=1);

namespace ThreadSafe\Plugin;

use ThreadSafe\Core\Paths;

/**
 * The website type plugins: every directory below `plugins/` with a `plugin.json`. Loading registers a PSR-4 autoloader
 * for the namespaces the manifest names (so that a plugin directory works without an entry in composer.json) and keeps
 * the manifests; the entry class is only created when the plugin is asked for.
 *
 * Plugins are code with the rights of the program and come from the author only; there is no way to install one from
 * the web interface.
 */
final class PluginRegistry
{
    /** @var array<string, WebsiteType> */
    private array $instances = [];

    /**
     * @param array<string, PluginManifest> $manifests by plugin id
     */
    private function __construct(private readonly array $manifests)
    {
    }

    /**
     * @param string|null $directory the plugins directory; default: `plugins/` of the program
     *
     * @throws PluginError a plugin directory is broken
     */
    public static function load(?string $directory = null): self
    {
        $directory ??= Paths::plugins();
        $manifests = [];
        foreach (glob(rtrim($directory, '/\\') . '/*', GLOB_ONLYDIR) ?: [] as $path) {
            if (!is_file($path . '/plugin.json')) {
                continue;
            }
            $manifest = PluginManifest::fromDirectory($path);
            $manifests[$manifest->id] = $manifest;
            self::registerAutoload($manifest);
        }
        ksort($manifests);

        return new self($manifests);
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->manifests);
    }

    public function has(string $id): bool
    {
        return isset($this->manifests[$id]);
    }

    public function manifest(string $id): PluginManifest
    {
        return $this->manifests[$id] ?? throw new PluginError("There is no website type \"$id\" (available: " . implode(', ', $this->ids()) . ').');
    }

    /**
     * The website type of a plugin.
     *
     * @throws PluginError no such plugin, or its entry class is missing or not a {@see WebsiteType}
     */
    public function get(string $id): WebsiteType
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        $manifest = $this->manifest($id);
        if (!class_exists($manifest->class)) {
            throw new PluginError("Plugin \"$id\": the class \"{$manifest->class}\" does not exist (check \"class\" and \"autoload\" in plugin.json).");
        }
        $type = new ($manifest->class)();
        if (!$type instanceof WebsiteType) {
            throw new PluginError("Plugin \"$id\": \"{$manifest->class}\" does not implement " . WebsiteType::class . '.');
        }
        if ($type->id() !== $id) {
            throw new PluginError("Plugin \"$id\": its website type calls itself \"{$type->id()}\".");
        }

        return $this->instances[$id] = $type;
    }

    /**
     * Every website type, in id order.
     *
     * @return list<WebsiteType>
     */
    public function all(): array
    {
        return array_map($this->get(...), $this->ids());
    }

    private static function registerAutoload(PluginManifest $manifest): void
    {
        foreach ($manifest->autoload as $prefix => $directory) {
            spl_autoload_register(static function (string $class) use ($prefix, $directory): void {
                if (!str_starts_with($class, $prefix)) {
                    return;
                }
                $file = $directory . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            });
        }
    }
}
