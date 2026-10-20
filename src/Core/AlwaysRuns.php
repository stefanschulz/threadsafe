<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

/**
 * A step that runs on every applied run, also when its plan has no changes: it does not bring the system into a state
 * but acts on the result (checks it, lets the application tidy up). In a dry run it is not run - there is no result
 * to act on yet - and shows up as "unchanged" with a notice that says what it will do.
 *
 * It is reported as {@see StepStatus::Ran}. It may still have changes in its plan (then it is applied as any step).
 */
interface AlwaysRuns extends Step
{
}
