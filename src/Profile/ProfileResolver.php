<?php

declare(strict_types=1);

namespace ThreadSafe\Profile;

use ThreadSafe\Core\Paths;
use ThreadSafe\Plugin\PluginError;
use ThreadSafe\Plugin\PluginRegistry;

/**
 * Asks the website type of a profile what it needs to know about the site, and combines the answers with the profile.
 */
final class ProfileResolver
{
    public function __construct(private readonly PluginRegistry $plugins, private readonly ?string $dataDirectory = null)
    {
    }

    /**
     * @throws ProfileException the site cannot be backed up with this profile; the message says why
     */
    public function resolve(SiteProfile $profile): ResolvedProfile
    {
        try {
            $type = $this->plugins->backupable($profile->type);
        } catch (PluginError $e) {
            throw new ProfileException("Profile \"{$profile->id}\": " . $e->getMessage(), 0, $e);
        }

        $root = is_dir($profile->path) ? realpath($profile->path) : false;
        if ($root === false || !is_readable($root)) {
            throw new ProfileException("The site path \"{$profile->path}\" is not a readable directory.");
        }
        $site = $type->detect($root);
        if ($site === null) {
            throw new ProfileException("\"{$profile->path}\" is not an installation of the website type \"{$profile->type}\".");
        }

        try {
            $database = $profile->database ?? $type->databaseSettings($root);
        } catch (PluginError $e) {
            throw new ProfileException($e->getMessage(), 0, $e);
        }

        $defaults = $profile->useDefaultFilters ? $type->defaultFilters($site) : null;
        $excludePaths = array_values(array_unique([...($defaults->excludePaths ?? []), ...$profile->excludePaths]));
        $structureOnly = array_values(array_unique([...($defaults->structureOnlyTables ?? []), ...$profile->structureOnlyTables]));

        $literals = [];
        foreach ([$profile->storage, $this->dataDirectory ?? Paths::data()] as $inside) {
            $relative = self::relativeInside($root, $inside);
            if ($relative !== null) {
                $literals[] = $relative;
            }
        }

        return new ResolvedProfile($profile, $type, $site, $root, $database, $excludePaths, $structureOnly, $literals);
    }

    /**
     * The path of $absolute relative to the site root if it lies strictly inside, also if it does not exist yet (the
     * storage is created at the first backup): the nearest existing parent is resolved and the rest appended.
     */
    public static function relativeInside(string $root, string $absolute): ?string
    {
        $resolved = self::resolveLeniently($absolute);
        if ($resolved === null) {
            return null;
        }
        $base = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $target = rtrim(str_replace('\\', '/', $resolved), '/') . '/';

        return str_starts_with($target, $base) && strlen($target) > strlen($base) ? trim(substr($target, strlen($base)), '/') : null;
    }

    private static function resolveLeniently(string $path): ?string
    {
        $rest = [];
        $current = rtrim($path, '/\\');
        while ($current !== '' && ($real = realpath($current)) === false) {
            $rest[] = basename($current);
            $parent = dirname($current);
            if ($parent === $current) {
                return null;
            }
            $current = $parent;
        }
        if ($current === '') {
            return null;
        }

        return ($real ?? '') . ($rest === [] ? '' : '/' . implode('/', array_reverse($rest)));
    }
}
