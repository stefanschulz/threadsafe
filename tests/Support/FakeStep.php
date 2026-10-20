<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use Closure;
use ThreadSafe\Core\Change;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;

/**
 * Configurable step for runner tests. Records "plan:<id>" and "apply:<id>" in the event log.
 */
final class FakeStep implements Step
{
    private Plan $plan;

    /**
     * @param int                       $changes  number of changes in the plan (0 = already correct)
     * @param Closure(Context): void|null $onPlan   runs inside plan()
     * @param Closure(Context): void|null $onApply  runs inside apply(); may throw
     */
    public function __construct(
        private readonly string $id,
        private readonly EventLog $log,
        int $changes = 1,
        private readonly ?Closure $onPlan = null,
        private readonly ?Closure $onApply = null,
    ) {
        $this->plan = new Plan();
        for ($i = 1; $i <= $changes; ++$i) {
            $this->plan->add(Change::update("target-$id-$i", "change $i of $id", 'old', 'new'));
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return "Step $this->id";
    }

    public function plan(Context $context): Plan
    {
        $this->log->add('plan:' . $this->id);
        if ($this->onPlan !== null) {
            ($this->onPlan)($context);
        }

        return $this->plan;
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $this->log->add('apply:' . $this->id);
        if ($this->onApply !== null) {
            ($this->onApply)($context);
        }

        return new StepResult($plan->changeCount());
    }
}
