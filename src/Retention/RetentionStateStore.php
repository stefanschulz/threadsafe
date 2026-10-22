<?php

declare(strict_types=1);

namespace ThreadSafe\Retention;

use JsonException;
use RuntimeException;
use ThreadSafe\Core\AtomicFile;
use ThreadSafe\Profile\ProfileStore;

/**
 * The state files of retention, one JSON file per profile. Unreadable state reads as none.
 */
final class RetentionStateStore
{
    public function __construct(private readonly string $directory)
    {
    }

    public function load(string $profileId): RetentionState
    {
        $path = $this->path($profileId);
        $content = is_file($path) ? @file_get_contents($path) : false;
        if ($content === false) {
            return new RetentionState();
        }
        try {
            $data = json_decode($content, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new RetentionState();
        }

        return is_array($data) ? RetentionState::fromArray($data) : new RetentionState();
    }

    public function save(string $profileId, RetentionState $state): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            return;
        }
        try {
            AtomicFile::write($this->path($profileId), json_encode($state->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        } catch (RuntimeException|JsonException) {
            // the state is a convenience: a backup run must not fail because it cannot be written
        }
    }

    public function delete(string $profileId): void
    {
        @unlink($this->path($profileId));
    }

    private function path(string $profileId): string
    {
        if (!ProfileStore::validId($profileId)) {
            throw new RuntimeException("\"$profileId\" is not a profile id.");
        }

        return $this->directory . '/' . $profileId . '.json';
    }
}
