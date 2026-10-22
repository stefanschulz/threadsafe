<?php

declare(strict_types=1);

namespace ThreadSafe\Schedule;

use JsonException;
use ThreadSafe\Core\AtomicFile;
use ThreadSafe\Profile\ProfileStore;

/**
 * The state files of the scheduler, one JSON file per profile in a directory (docs/PHASE5.md 3.1). A file that cannot be read is
 * the same as no file: the state is a cache, and the scheduler starts again from "now" without it.
 */
final class ScheduleStore
{
    public function __construct(private readonly string $directory)
    {
    }

    public function load(string $profileId): ?ScheduleState
    {
        $content = is_file($this->path($profileId)) ? @file_get_contents($this->path($profileId)) : false;
        if ($content === false) {
            return null;
        }
        try {
            $data = json_decode($content, true, 4, JSON_THROW_ON_ERROR);

            return is_array($data) ? ScheduleState::fromArray($data) : null;
        } catch (JsonException|ScheduleException) {
            return null;
        }
    }

    /**
     * @throws ScheduleException the file cannot be written
     */
    public function save(string $profileId, ScheduleState $state): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new ScheduleException("Cannot create the directory \"{$this->directory}\".");
        }
        try {
            AtomicFile::write($this->path($profileId), json_encode($state->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        } catch (\RuntimeException $e) {
            throw new ScheduleException($e->getMessage(), 0, $e);
        }
    }

    public function delete(string $profileId): void
    {
        @unlink($this->path($profileId));
    }

    private function path(string $profileId): string
    {
        if (!ProfileStore::validId($profileId)) {
            throw new ScheduleException("\"$profileId\" is not a profile id.");
        }

        return $this->directory . '/' . $profileId . '.json';
    }
}
