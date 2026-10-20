<?php

declare(strict_types=1);

namespace ThreadSafe\Archive;

/**
 * One entry of a ZIP archive as its central directory describes it.
 */
final readonly class Entry
{
    /**
     * @param int      $method         0 = stored, 8 = deflated
     * @param int      $localOffset    where the local header starts in the part
     * @param int      $mtime          Unix time (UTC)
     * @param int|null $mode           Unix mode (type and permissions) if the archive says it, otherwise null
     */
    public function __construct(
        public string $name,
        public int $method,
        public int $flags,
        public int $crc32,
        public int $compressedSize,
        public int $size,
        public int $localOffset,
        public int $mtime,
        public ?int $mode,
        public bool $isDirectory,
    ) {
    }

    public function isEncrypted(): bool
    {
        return ($this->flags & ZipFormat::FLAG_ENCRYPTED) !== 0;
    }
}
