<?php

declare(strict_types=1);

namespace ThreadSafe\App;

use ThreadSafe\Plugin\PluginError;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ConnectionTest;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Profile\TestReport;

/**
 * What can be done with the site profiles, for both front ends: the command `profile` and the pages of the web interface call this
 * and format the results their own way (docs/PHASE4.md 3.3). It returns plain data and value objects and prints nothing.
 */
final class ProfileService
{
    public function __construct(
        private readonly ProfileStore $store,
        private readonly PluginRegistry $plugins,
        private readonly ?string $dataDirectory = null,
    ) {
    }

    /**
     * @return array{profiles: list<SiteProfile>, damaged: list<string>} the profiles that can be read, and the ids of the files that cannot
     */
    public function listing(): array
    {
        $profiles = $this->store->all();

        return [
            'profiles' => $profiles,
            'damaged' => array_values(array_diff($this->store->ids(), array_map(static fn (SiteProfile $p): string => $p->id, $profiles))),
        ];
    }

    /**
     * @throws ProfileException there is no such profile or its file is damaged
     */
    public function load(string $id): SiteProfile
    {
        return $this->store->load($id);
    }

    public function exists(string $id): bool
    {
        return $this->store->exists($id);
    }

    /**
     * The website types that can be backed up.
     *
     * @return array<string, string> id => title
     */
    public function types(): array
    {
        $types = [];
        foreach ($this->plugins->ids() as $id) {
            try {
                $this->plugins->backupable($id);
                $types[$id] = $this->plugins->get($id)->title();
            } catch (PluginError) {
                continue;
            }
        }

        return $types;
    }

    /**
     * Whether an id can be given to a new profile.
     *
     * @throws ProfileException the id is not allowed or taken
     */
    public function assertFreeId(string $id): void
    {
        if (!ProfileStore::validId($id)) {
            throw new ProfileException('The profile id must be 1-40 lower-case letters, digits, hyphens or underscores, starting with a letter or digit.');
        }
        if ($this->store->exists($id)) {
            throw new ProfileException("The profile \"$id\" exists already.");
        }
    }

    /**
     * Looks at a directory with the eyes of a website type, without storing anything.
     *
     * @throws ProfileException the type does not exist, the directory cannot be read or is not an installation of the type
     */
    public function detect(string $type, string $path): Detection
    {
        try {
            $backupable = $this->plugins->backupable($type);
        } catch (PluginError $e) {
            throw new ProfileException($e->getMessage(), 0, $e);
        }
        $root = is_dir($path) ? realpath($path) : false;
        if ($root === false || !is_readable($root)) {
            throw new ProfileException("The site path \"$path\" is not a readable directory.");
        }
        $site = $backupable->detect($root) ?? throw new ProfileException("\"$path\" is not an installation of the website type \"$type\".");
        try {
            $database = $backupable->databaseSettings($root);
        } catch (PluginError $e) {
            throw new ProfileException($e->getMessage(), 0, $e);
        }
        $defaults = $backupable->defaultFilters($site);

        return new Detection($site, $root, $database, count($defaults->excludePaths), count($defaults->structureOnlyTables));
    }

    public function test(SiteProfile $profile): TestReport
    {
        return (new ConnectionTest($this->plugins, $this->dataDirectory))->run($profile);
    }

    /**
     * Stores a profile, after testing it unless told not to. A profile that fails its test is not stored.
     */
    public function store(SiteProfile $profile, bool $test = true): StoreResult
    {
        $report = null;
        if ($test) {
            $report = $this->test($profile);
            if (!$report->passed()) {
                return new StoreResult($profile, $report, false);
            }
        }
        try {
            $this->store->save($profile);
        } catch (ProfileException $e) {
            return new StoreResult($profile, $report, false, $e->getMessage());
        }

        return new StoreResult($profile, $report, true);
    }

    /**
     * Removes the profile; its backups are not touched.
     *
     * @throws ProfileException there is no such profile
     */
    public function remove(string $id): void
    {
        $this->store->load($id);
        $this->store->delete($id);
    }
}
