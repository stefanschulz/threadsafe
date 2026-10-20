<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

/**
 * Records the order in which test doubles are called.
 */
final class EventLog
{
    /** @var list<string> */
    public array $events = [];

    public function add(string $event): void
    {
        $this->events[] = $event;
    }
}
