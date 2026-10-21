<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

use ThreadSafe\Engine\Budget;

/**
 * A step whose work can be too long for one request (changing the content of a big database) and therefore can be done in
 * ticks. The {@see TickedRunner} calls {@see applyTick()} again and again, with the state the previous call returned, until the
 * step says it is finished; the {@see Runner} of the command line calls {@see apply()}, which does the same in a loop without a
 * time limit.
 *
 * The state is plain data (JSON) and holds everything needed to continue in another process. Work that was done in a tick
 * must be done for good (committed) when the tick returns, and doing a unit of work twice (after a crash between the work
 * and the saving of the state) must be harmless.
 */
interface ResumableStep extends Step
{
    /**
     * What the step will do, without finding out how much there is to do: the plan the ticked runner uses, because the exact
     * plan ({@see plan()}) means doing the whole work once in a dry run. Like plan(), it changes nothing.
     */
    public function planBrief(Context $context): Plan;

    /**
     * Works for at most the budget (at least one unit of work) and returns the new state.
     *
     * @param array<string, mixed> $state empty at the first call
     *
     * @throws \Throwable the step failed
     */
    public function applyTick(Context $context, Budget $budget, array $state): StepTick;
}
