<?php

declare(strict_types=1);

namespace ThreadSafe\Engine;

use Closure;
use Throwable;

/**
 * Drives a {@see Task} tick by tick: lock the run, load its state, let the task work for one budget, save the state, repeat.
 *
 * The state is saved after **every** tick and loaded again before the next one, also within one process: the path a
 * crashed run takes on resume is the path every run takes, so it cannot rot unnoticed. A task that throws marks its run
 * as failed (message stored, no stack trace or values) and the exception is passed on to the caller.
 */
final class Executor
{
    /** @var Closure(): Budget */
    private readonly Closure $budgets;

    /**
     * @param Closure(): Budget|null $budgets creates the budget of each tick; default: {@see Budget::forThisProcess()}
     */
    public function __construct(
        private readonly RunStore $store,
        private readonly Task $task,
        ?Closure $budgets = null,
    ) {
        $this->budgets = $budgets ?? Budget::forThisProcess(...);
    }

    /**
     * Creates a run; nothing has been done yet.
     *
     * @param array<string, mixed> $parameters
     */
    public function start(array $parameters = []): Run
    {
        return $this->store->create($this->task->id(), $this->task->initialState($parameters));
    }

    /**
     * Works on a run until it is finished, a tick throws, or $maxTicks ticks have been done (then the run stays "running"
     * and can be continued by calling this again - here or in another process).
     *
     * @param (callable(Run): void)|null $onTick called after each saved tick (progress display)
     *
     * @throws EngineException the run does not exist, belongs to another task, is failed, or is being executed by another process
     * @throws Throwable       what the task threw; the run is stored as failed first
     */
    public function execute(string $id, ?int $maxTicks = null, ?callable $onTick = null): Run
    {
        $lock = Lock::tryAcquire($this->store->lockPath($id), 'run ' . $id);
        if ($lock === null) {
            throw new EngineException("Run \"$id\" is being executed by another process (" . (Lock::holder($this->store->lockPath($id)) ?? 'holder unknown') . ').');
        }

        $ticks = 0;
        while (true) {
            $run = $this->store->load($id);
            if ($run->task !== $this->task->id()) {
                throw new EngineException("Run \"$id\" belongs to the task \"{$run->task}\", not \"{$this->task->id()}\".");
            }
            if ($run->status === RunStatus::Finished) {
                return $run;
            }
            if ($run->status === RunStatus::Failed) {
                throw new EngineException("Run \"$id\" has failed ({$run->error}); start a new run.");
            }
            if ($maxTicks !== null && $ticks >= $maxTicks) {
                return $run;
            }

            $budget = ($this->budgets)();
            $budget->start();
            try {
                $outcome = $this->task->tick($run->state, $budget);
                $next = $run->with($outcome->finished ? RunStatus::Finished : RunStatus::Running, $outcome->state, null, $outcome->progress);
                $this->store->save($next);
            } catch (Throwable $e) {
                $this->store->save($run->with(RunStatus::Failed, $run->state, $e::class . ': ' . $e->getMessage()));

                throw $e;
            }
            ++$ticks;
            if ($onTick !== null) {
                $onTick($next);
            }
            if ($outcome->finished) {
                return $next;
            }
        }
    }

    /**
     * Sets a failed run going again from the state of its last good tick (a failure does not change the state). For work whose
     * cause the person can remove - a full disk, a wrong password, a statement the server refused - and that can continue where it
     * stopped.
     *
     * @throws EngineException the run does not exist or has not failed
     */
    public function retry(string $id): Run
    {
        $run = $this->store->load($id);
        if ($run->status !== RunStatus::Failed) {
            throw new EngineException("Run \"$id\" has not failed; there is nothing to retry.");
        }
        $again = $run->with(RunStatus::Running, $run->state, null);
        $this->store->save($again);

        return $again;
    }

    /**
     * Continues a run whose process died.
     *
     * @param (callable(Run): void)|null $onTick
     *
     * @throws EngineException as {@see execute()}
     */
    public function resume(string $id, ?callable $onTick = null): Run
    {
        $run = $this->store->load($id);
        if ($run->status === RunStatus::Running && !$this->store->isInterrupted($run)) {
            throw new EngineException("Run \"$id\" is still being executed by another process.");
        }

        return $this->execute($id, null, $onTick);
    }
}
