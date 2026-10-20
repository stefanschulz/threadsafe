<?php

declare(strict_types=1);

namespace ThreadSafe\Engine;

/**
 * What a tick leaves behind: the new state, whether the task is done, and a line for the progress display.
 */
final readonly class TickOutcome
{
    /**
     * @param array<string, mixed> $state    JSON-serialisable
     * @param string|null          $progress a short line such as "dumping table ps_product (12 of 299)"
     */
    public function __construct(public array $state, public bool $finished, public ?string $progress = null)
    {
    }
}
