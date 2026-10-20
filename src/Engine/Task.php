<?php

declare(strict_types=1);

namespace ThreadSafe\Engine;

/**
 * A job that runs in ticks. A task object holds no state of its own: everything it needs to continue is in the plain-data
 * state it is given and returns, which the {@see Executor} saves after every tick. So a task can go on in another process,
 * after a crash, or in the next web request, and every run exercises that path.
 */
interface Task
{
    /** Short identifier of the kind of task, e.g. "backup"; part of run ids. Lower-case letters, digits, hyphens. */
    public function id(): string;

    /**
     * The state a new run starts with.
     *
     * @param array<string, mixed> $parameters what the caller asked for (profile, options)
     *
     * @return array<string, mixed> JSON-serialisable data
     */
    public function initialState(array $parameters): array;

    /**
     * Works for at most the budget and returns the new state. Must check {@see Budget::exhausted()} between its units of
     * work and stop at a boundary from which it can continue. A tick that does no progress at all is a bug.
     *
     * @param array<string, mixed> $state
     *
     * @throws \Throwable any failure; the run is then marked as failed
     */
    public function tick(array $state, Budget $budget): TickOutcome;
}
