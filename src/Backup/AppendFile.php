<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

use RuntimeException;

/**
 * A file that grows in pieces over several ticks. It is opened at the length the run state remembers: whatever lies
 * beyond (written by a process that died before it saved its state) is cut off first, so the file is exactly what the
 * state says, however often the work was interrupted.
 */
final class AppendFile
{
    /** @var resource */
    private $handle;

    private int $size;

    /**
     * @param int $size the length the file must have; 0 starts a new file
     *
     * @throws RuntimeException the file cannot be opened or is shorter than the state says
     */
    public function __construct(private readonly string $path, int $size)
    {
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException("Cannot open \"$path\" for writing.");
        }
        $this->handle = $handle;
        $stat = fstat($handle);
        if ($stat === false || $stat['size'] < $size) {
            fclose($handle);

            throw new RuntimeException("The file \"$path\" is shorter than the saved state says.");
        }
        ftruncate($handle, max(0, $size));
        fseek($handle, 0, SEEK_END);
        $this->size = $size;
    }

    /**
     * @throws RuntimeException the write fails (disk full?)
     */
    public function write(string $text): void
    {
        if ($text === '') {
            return;
        }
        if (fwrite($this->handle, $text) !== strlen($text)) {
            throw new RuntimeException("Cannot write to \"{$this->path}\" (disk full?).");
        }
        $this->size += strlen($text);
    }

    /** The length of the file so far. */
    public function size(): int
    {
        return $this->size;
    }

    public function close(): void
    {
        fflush($this->handle);
        fclose($this->handle);
    }
}
