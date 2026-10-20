<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

use OutOfBoundsException;

/**
 * Everything a step may use: the request of the run (an application-specific value object), the journal, a logger
 * and a small bag of state that steps share.
 *
 * State may be written in plan() only to record what was *discovered* (never changes to the system), so that later
 * steps can plan from it.
 */
final class Context
{
    /** @var array<string, mixed> */
    private array $state = [];

    public function __construct(
        public readonly object $request,
        public readonly Journal $journal,
        public readonly Logger $logger,
        public readonly bool $dryRun,
    ) {
    }

    public function set(string $key, mixed $value): void
    {
        $this->state[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->state);
    }

    /**
     * @throws OutOfBoundsException if no step has stored the key (a step ran before the one that provides it)
     */
    public function get(string $key): mixed
    {
        if (!array_key_exists($key, $this->state)) {
            throw new OutOfBoundsException("No state \"$key\" has been stored yet.");
        }

        return $this->state[$key];
    }
}
