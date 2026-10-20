<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

/**
 * A file or an (empty or not) directory that goes into the backup.
 */
final readonly class ScanItem
{
    /**
     * @param string $path  relative to the site root, no leading slash, never ending in a slash
     * @param bool   $isDirectory
     * @param int    $size  bytes at scan time (0 for directories)
     * @param int    $mtime Unix time
     * @param int    $mode  Unix mode including the file type bits
     */
    public function __construct(
        public string $path,
        public bool $isDirectory,
        public int $size,
        public int $mtime,
        public int $mode,
    ) {
    }

    /** @return array{string, int, int, int, int} */
    public function toArray(): array
    {
        return [$this->path, $this->isDirectory ? 1 : 0, $this->size, $this->mtime, $this->mode];
    }

    /** @param array<mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self((string) $row[0], (int) $row[1] === 1, (int) $row[2], (int) $row[3], (int) $row[4]);
    }
}
