<?php

declare(strict_types=1);

namespace ThreadSafe\Archive;

/**
 * A closed archive part: where it is, how big, its SHA-256 and how many entries it holds.
 */
final readonly class PartInfo
{
    public function __construct(
        public string $path,
        public int $size,
        public string $sha256,
        public int $entries,
    ) {
    }
}
