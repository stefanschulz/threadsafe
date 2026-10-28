<?php

declare(strict_types=1);

namespace ThreadSafe\Engine;

use JsonException;

/**
 * Where runs are kept between ticks: one JSON file per run in a directory (mode 0700, files 0600), written atomically.
 * The state of a run can hold paths and, for a profile, access data; it is private.
 */
final class RunStore
{
    private const string ID_PATTERN = '/^[a-z][a-z0-9-]{0,40}-\d{8}-\d{6}-[0-9a-f]{8}$/';

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * Creates a run with its first state.
     *
     * @param array<string, mixed> $state
     */
    public function create(string $task, array $state): Run
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,40}$/', $task) !== 1) {
            throw new EngineException("\"$task\" is not a valid task id.");
        }
        $now = time();
        $run = new Run(sprintf('%s-%s-%s', $task, gmdate('Ymd-His', $now), bin2hex(random_bytes(4))), $task, RunStatus::Running, 0, $now, $now, null, null, $state);
        $this->save($run);

        return $run;
    }

    /**
     * @throws EngineException no such run, or its file is damaged
     */
    public function load(string $id): Run
    {
        $path = $this->path($id);
        $content = is_file($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw new EngineException("There is no run \"$id\".");
        }
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new EngineException("The file of run \"$id\" is damaged: " . $e->getMessage(), 0, $e);
        }
        $status = is_array($data) && is_string($data['status'] ?? null) ? RunStatus::tryFrom($data['status']) : null;
        if (!is_array($data) || ($data['format'] ?? null) !== 1 || $status === null || ($data['id'] ?? null) !== $id || !is_string($data['task'] ?? null)
            || !is_int($data['ticks'] ?? null) || !is_int($data['created'] ?? null) || !is_int($data['updated'] ?? null) || !is_array($data['state'] ?? null)) {
            throw new EngineException("The file of run \"$id\" is not a run file.");
        }

        /** @var array<string, mixed> $state */
        $state = $data['state'];

        return new Run(
            $id,
            $data['task'],
            $status,
            $data['ticks'],
            $data['created'],
            $data['updated'],
            is_string($data['error'] ?? null) ? $data['error'] : null,
            is_string($data['progress'] ?? null) ? $data['progress'] : null,
            $state,
        );
    }

    /**
     * @throws EngineException the state cannot be written
     */
    public function save(Run $run): void
    {
        $path = $this->path($run->id);
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new EngineException("Cannot create the run directory \"{$this->directory}\".");
        }
        try {
            $json = json_encode($run->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new EngineException("The state of run \"{$run->id}\" cannot be stored as JSON: " . $e->getMessage(), 0, $e);
        }

        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
            throw new EngineException("Cannot write \"$temporary\".");
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            throw new EngineException("Cannot replace \"$path\".");
        }
        @unlink($this->path($run->id, '.live'));
    }

    /**
     * Notes that a tick of the run has begun, or what it is doing ("note"): a small file next to the run that a page reads while the tick is
     * still working. It is for the eyes only and never decides anything; {@see save()} removes it, because a saved tick says it all.
     */
    public function saveLive(string $id, float $started, string $note = ''): void
    {
        $path = $this->path($id, '.live');
        if (!is_dir($this->directory)) {
            return;
        }
        $json = json_encode(['started' => $started, 'note' => $note, 'at' => microtime(true)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if ($json === false || @file_put_contents($temporary, $json) === false) {
            return;
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
        }
    }

    /**
     * What {@see saveLive()} left: when the tick that is working began, its last note and when it was made; null if no tick is working.
     *
     * @return array{started: float, note: string, at: float}|null
     */
    public function live(string $id): ?array
    {
        $text = @file_get_contents($this->path($id, '.live'));
        $data = $text === false ? null : json_decode($text, true);
        if (!is_array($data) || !is_numeric($data['started'] ?? null)) {
            return null;
        }

        return ['started' => (float) $data['started'], 'note' => is_string($data['note'] ?? null) ? $data['note'] : '', 'at' => is_numeric($data['at'] ?? null) ? (float) $data['at'] : (float) $data['started']];
    }

    public function delete(string $id): void
    {
        @unlink($this->path($id));
        @unlink($this->path($id, '.live'));
        @unlink($this->lockPath($id));
    }

    /**
     * All runs, newest first. Files that are not run files are skipped.
     *
     * @return list<Run>
     */
    public function all(?string $task = null): array
    {
        $runs = [];
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            $id = basename($file, '.json');
            if (preg_match(self::ID_PATTERN, $id) !== 1) {
                continue;
            }
            try {
                $run = $this->load($id);
            } catch (EngineException) {
                continue;
            }
            if ($task === null || $run->task === $task) {
                $runs[] = $run;
            }
        }
        usort($runs, static fn (Run $a, Run $b): int => [$b->created, $b->id] <=> [$a->created, $a->id]);

        return $runs;
    }

    /** Where the lock of a run lives; held by whoever executes the run. */
    public function lockPath(string $id): string
    {
        return $this->path($id, '.lock');
    }

    /**
     * True if the run should be going but no process holds its lock: the process died. Such a run can be resumed.
     */
    public function isInterrupted(Run $run): bool
    {
        return $run->status === RunStatus::Running && !Lock::isHeld($this->lockPath($run->id));
    }

    private function path(string $id, string $extension = '.json'): string
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new EngineException("\"$id\" is not a valid run id.");
        }

        return $this->directory . '/' . $id . $extension;
    }
}
