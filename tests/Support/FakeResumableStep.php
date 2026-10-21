<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use ThreadSafe\Core\Change;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\ResumableStep;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Core\StepTick;
use ThreadSafe\Engine\Budget;

/**
 * A step that needs a given number of ticks. Records "plan:<id>", "brief:<id>" and "tick:<id>:<n>" in the event log; `apply()` does
 * all the ticks at once, like the real resumable steps.
 */
final class FakeResumableStep implements ResumableStep
{
    public function __construct(private readonly string $id, private readonly EventLog $log, private readonly int $ticks = 3)
    {
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

        return (new Plan())->add(Change::update("target-$this->id", 'exact plan'));
    }

    public function planBrief(Context $context): Plan
    {
        $this->log->add('brief:' . $this->id);

        return (new Plan())->add(Change::update("target-$this->id", 'brief plan'));
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $state = [];
        do {
            $tick = $this->applyTick($context, Budget::unlimited(), $state);
            $state = $tick->state;
        } while (!$tick->finished);

        return $tick->result ?? new StepResult(0);
    }

    public function applyTick(Context $context, Budget $budget, array $state): StepTick
    {
        $done = (int) ($state['done'] ?? 0) + 1;
        $this->log->add("tick:$this->id:$done");
        if ($done < $this->ticks) {
            return new StepTick(['done' => $done], false, null, null, "working $done of $this->ticks");
        }

        return new StepTick(['done' => $done], true, new StepResult($done), (new Plan())->add(Change::update("target-$this->id", 'as it turned out'))->notice(Notice::info('finished')));
    }
}
