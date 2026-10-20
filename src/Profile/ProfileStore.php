<?php

declare(strict_types=1);

namespace ThreadSafe\Profile;

use JsonException;

/**
 * Where site profiles are kept: one JSON file per profile in a directory (mode 0700, files 0600 - a profile can hold a
 * database password), written atomically. The id is the file name, so it is validated against path tricks before it is
 * ever used in a path.
 */
final class ProfileStore
{
    public function __construct(private readonly string $directory)
    {
    }

    public function exists(string $id): bool
    {
        return self::validId($id) && is_file($this->path($id));
    }

    /**
     * @throws ProfileException no such profile, or its file is damaged
     */
    public function load(string $id): SiteProfile
    {
        if (!self::validId($id)) {
            throw new ProfileException("\"$id\" is not a valid profile id.");
        }
        $path = $this->path($id);
        $content = is_file($path) ? @file_get_contents($path) : false;
        if ($content === false) {
            throw new ProfileException("There is no profile \"$id\".");
        }
        try {
            $data = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ProfileException("The file of profile \"$id\" is damaged: " . $e->getMessage(), 0, $e);
        }
        if (!is_array($data)) {
            throw new ProfileException("The file of profile \"$id\" is not a profile.");
        }
        try {
            $profile = SiteProfile::fromArray($data);
        } catch (ProfileException $e) {
            throw new ProfileException("Profile \"$id\": " . $e->getMessage(), 0, $e);
        }
        if ($profile->id !== $id) {
            throw new ProfileException("The file of profile \"$id\" says its id is \"{$profile->id}\".");
        }

        return $profile;
    }

    /**
     * @throws ProfileException the file cannot be written
     */
    public function save(SiteProfile $profile): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new ProfileException("Cannot create the profile directory \"{$this->directory}\".");
        }
        $json = json_encode($profile->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $temporary = $this->path($profile->id) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        // created with mode 0600 from the start: the password is never in a file others can read
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new ProfileException("Cannot write \"$temporary\".");
        }
        @chmod($temporary, 0600);
        $written = fwrite($handle, $json . "\n");
        fclose($handle);
        if ($written !== strlen($json) + 1 || !@rename($temporary, $this->path($profile->id))) {
            @unlink($temporary);

            throw new ProfileException("Cannot store profile \"{$profile->id}\" (disk full or no permission).");
        }
    }

    public function delete(string $id): void
    {
        if (self::validId($id)) {
            @unlink($this->path($id));
        }
    }

    /**
     * The ids of all stored profiles, sorted.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        $ids = [];
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            $id = basename($file, '.json');
            if (self::validId($id)) {
                $ids[] = $id;
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * All profiles that can be read, sorted by id; a damaged file is left out (load it to see why).
     *
     * @return list<SiteProfile>
     */
    public function all(): array
    {
        $profiles = [];
        foreach ($this->ids() as $id) {
            try {
                $profiles[] = $this->load($id);
            } catch (ProfileException) {
                continue;
            }
        }

        return $profiles;
    }

    public static function validId(string $id): bool
    {
        return preg_match(SiteProfile::ID_PATTERN, $id) === 1;
    }

    private function path(string $id): string
    {
        return $this->directory . '/' . $id . '.json';
    }
}
