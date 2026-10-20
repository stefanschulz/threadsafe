<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

/**
 * What one call of the scanner found.
 */
final readonly class ScanBatch
{
    /**
     * @param list<ScanItem>       $items    in the order the archive will have them
     * @param array<string, mixed> $state    to pass to the next call
     * @param list<string>         $warnings things that were skipped, with the reason
     * @param bool                 $done     the whole tree has been walked
     */
    public function __construct(
        public array $items,
        public array $state,
        public array $warnings,
        public bool $done,
    ) {
    }
}
