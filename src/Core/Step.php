<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

/**
 * One unit of work of a run (e.g. "write database settings to parameters.php").
 *
 * Contract:
 *  - plan() never changes the system. It may read, and it may store discovered facts in the Context. It reports
 *    everything apply() would do as Changes; an empty plan means "already correct".
 *  - plan() must work from the initial state and the request alone, never from the effects of earlier steps,
 *    because all plans are computed before anything is applied (that is the dry run).
 *  - apply() is called only when the plan has changes, performs exactly them and writes each to the journal.
 *  - A step is idempotent: running it again after it succeeded yields an empty plan.
 *  - Failure is signalled by throwing; {@see Exception\StepFailed} (or any other throwable) ends the run,
 *    {@see Exception\SelfTestFailed} marks a failed self-test, {@see Exception\GuardFailed} a refused run.
 */
interface Step
{
    /** Stable identifier used in reports and the journal, e.g. "write-parameters". */
    public function id(): string;

    /** Short human-readable description, e.g. "Write database settings to parameters.php". */
    public function title(): string;

    public function plan(Context $context): Plan;

    public function apply(Context $context, Plan $plan): StepResult;
}
