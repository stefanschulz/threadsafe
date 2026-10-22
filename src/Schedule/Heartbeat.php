<?php

declare(strict_types=1);

namespace ThreadSafe\Schedule;

use JsonException;
use RuntimeException;
use ThreadSafe\Core\AtomicFile;

/**
 * The trace of the trigger (`data/heartbeat.json`): when it called last, how (`cli` or `web`) and what it did, written at every
 * call (docs/PHASE5.md 3.3). The dashboard shows it and warns when it stays away. Losing the file loses nothing but the warning.
 */
final class Heartbeat
{
    public const string CLI = 'cli';

    public const string WEB = 'web';

    public function __construct(private readonly string $file)
    {
    }

    /**
     * @param string $via  {@see CLI} or {@see WEB}
     * @param string $note what happened, one line, no secret
     */
    public function record(string $via, string $note, ?int $time = null): void
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return;
        }
        $data = ['time' => $time ?? time(), 'via' => $via === self::WEB ? self::WEB : self::CLI, 'note' => substr(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $note) ?? '', 0, 200)];
        try {
            AtomicFile::write($this->file, json_encode($data, JSON_THROW_ON_ERROR) . "\n");
        } catch (RuntimeException|JsonException) {
            // a heartbeat that cannot be written must not stop a backup
        }
    }

    /**
     * @return array{time: int, via: string, note: string}|null null if the trigger never called, or the file is damaged
     */
    public function read(): ?array
    {
        $content = is_file($this->file) ? @file_get_contents($this->file) : false;
        if ($content === false) {
            return null;
        }
        try {
            $data = json_decode($content, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($data) || !is_int($data['time'] ?? null) || !in_array($data['via'] ?? null, [self::CLI, self::WEB], true)) {
            return null;
        }

        return ['time' => $data['time'], 'via' => $data['via'], 'note' => is_string($data['note'] ?? null) ? $data['note'] : ''];
    }
}
