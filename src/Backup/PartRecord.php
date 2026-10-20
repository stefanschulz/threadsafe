<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

use ThreadSafe\Archive\PartInfo;

/**
 * A part of a finished backup as the sidecar file records it.
 */
final readonly class PartRecord
{
    public function __construct(public string $file, public int $size, public string $sha256, public int $entries)
    {
    }

    public static function fromPartInfo(PartInfo $info): self
    {
        return new self(basename($info->path), $info->size, $info->sha256, $info->entries);
    }

    /** @return array{file: string, size: int, sha256: string, entries: int} */
    public function toArray(): array
    {
        return ['file' => $this->file, 'size' => $this->size, 'sha256' => $this->sha256, 'entries' => $this->entries];
    }
}
