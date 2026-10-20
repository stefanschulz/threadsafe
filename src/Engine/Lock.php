<?php

declare(strict_types=1);

namespace ThreadSafe\Engine;

/**
 * An exclusive lock on a file (`flock`), held as long as the object lives.
 *
 * The operating system releases the lock when the process ends, however it ends, so a lock of a dead process is never
 * "stale": the next process simply gets it. A timestamp in the file could not tell the two apart. The file holds a note
 * about the holder for diagnosis only.
 */
final class Lock
{
    /** @var resource|null */
    private $handle;

    /**
     * @param resource $handle
     */
    private function __construct($handle, private readonly string $path)
    {
        $this->handle = $handle;
    }

    /**
     * Takes the lock if it is free; null if another process holds it. Does not wait.
     *
     * @param string $note what to write into the lock file (who holds it), for diagnosis
     *
     * @throws EngineException the lock file cannot be created
     */
    public static function tryAcquire(string $path, string $note = ''): ?self
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new EngineException("Cannot create the lock directory \"$directory\".");
        }
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new EngineException("Cannot open the lock file \"$path\".");
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }
        @chmod($path, 0600);
        ftruncate($handle, 0);
        fwrite($handle, 'pid ' . getmypid() . ($note !== '' ? ' ' . $note : '') . "\n");
        fflush($handle);

        return new self($handle, $path);
    }

    /** True if some process holds the lock right now. */
    public static function isHeld(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $lock = self::tryAcquire($path);
        if ($lock === null) {
            return true;
        }
        $lock->release();

        return false;
    }

    /** What the holder wrote into the lock file, or null. */
    public static function holder(string $path): ?string
    {
        $content = is_file($path) ? @file_get_contents($path) : false;

        return $content === false || trim($content) === '' ? null : trim($content);
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    public function __destruct()
    {
        $this->release();
    }
}
