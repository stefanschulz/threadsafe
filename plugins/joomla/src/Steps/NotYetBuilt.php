<?php

declare(strict_types=1);

namespace ThreadSafe\Joomla\Steps;

use ThreadSafe\Core\Context;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;

/**
 * The only step of the Joomla type until its adaptation exists (docs/PHASE6.md, work package 4): it refuses the run, so that
 * `migrate joomla` never reports success for a site that nothing was done to. Work package 4 replaces it.
 */
final class NotYetBuilt implements Step
{
    public function id(): string
    {
        return 'not-yet-built';
    }

    public function title(): string
    {
        return 'Adapt a Joomla site (not built yet)';
    }

    public function plan(Context $context): Plan
    {
        throw new GuardFailed('The website type Joomla can back up a site; restoring and migrating one is not built yet (phase 6, work package 4).');
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        throw new GuardFailed('The website type Joomla cannot adapt a site yet.');
    }
}
