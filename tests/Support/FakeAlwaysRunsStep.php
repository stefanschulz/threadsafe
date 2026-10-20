<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use Closure;
use ThreadSafe\Core\AlwaysRuns;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\StepResult;

/**
 * A step with an empty plan that must run on every applied run (like a self-test). Records "plan:<id>" and
 * "apply:<id>" in the event log.
 */
final class FakeAlwaysRunsStep implements AlwaysRuns
{
    /**
     * @param Closure(Context): void|null $onApply runs inside apply(); may throw
     */
    public function __construct(
        private readonly string $id,
        private readonly EventLog $log,
        private readonly ?Closure $onApply = null,
    ) {
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

        return new Plan();
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $this->log->add('apply:' . $this->id);
        if ($this->onApply !== null) {
            ($this->onApply)($context);
        }

        return new StepResult(0);
    }
}
