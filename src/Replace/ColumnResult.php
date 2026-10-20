<?php

declare(strict_types=1);

namespace ThreadSafe\Replace;

/**
 * What the rewriting found and changed in one column.
 */
final class ColumnResult
{
    public int $rowsChanged = 0;

    public readonly Stats $stats;

    /** A short excerpt of the first changed value, before and after. */
    public ?string $sampleBefore = null;

    public ?string $sampleAfter = null;

    public function __construct()
    {
        $this->stats = new Stats();
    }
}
