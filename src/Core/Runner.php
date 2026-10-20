<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

use DateTimeImmutable;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\Exception\SelfTestFailed;
use ThreadSafe\Core\Exception\StepFailed;
use Throwable;

/**
 * Runs guards and steps in order.
 *
 * 1. Every guard is checked; a violated guard ends the run (exit code 2).
 * 2. plan() of every step is called, in order. This alone is the dry run.
 * 3. Only if $apply is true: apply() of every step whose plan has changes, in order. The first failing step ends
 *    the run (exit code 3, or 4 for a failed self-test); steps already applied stay applied and are reported, the
 *    rest is reported as skipped.
 */
final class Runner
{
    /**
     * @param list<Step>  $steps
     * @param list<Guard> $guards
     */
    public function __construct(
        private readonly array $steps,
        private readonly array $guards = [],
    ) {
    }

    public function run(Context $context, bool $apply): RunResult
    {
        $started = new DateTimeImmutable();
        $dryRun = !$apply;

        // 1. guards
        foreach ($this->guards as $guard) {
            $problem = $guard->check($context);
            if ($problem !== null) {
                $context->logger->error("Guard \"{$guard->id()}\" refused the run: $problem");

                return new RunResult(
                    $this->report($dryRun, [], $started, $context),
                    ExitCode::GuardFailed,
                    new GuardFailed($problem),
                );
            }
        }

        // 2. plans
        /** @var array<string, Plan> $plans */
        $plans = [];
        foreach ($this->steps as $step) {
            try {
                $context->logger->debug("Planning \"{$step->id()}\"");
                $plans[$step->id()] = $step->plan($context);
            } catch (GuardFailed $e) {
                return new RunResult($this->report($dryRun, [], $started, $context), ExitCode::GuardFailed, $e);
            } catch (Throwable $e) {
                $failure = $e instanceof StepFailed ? $e->withStepId($step->id()) : StepFailed::in($step->id(), $e);

                return new RunResult($this->report($dryRun, [], $started, $context), $this->exitCodeFor($failure), $failure);
            }
        }

        // 3. apply
        $reports = [];
        $failure = null;
        foreach ($this->steps as $step) {
            $plan = $plans[$step->id()];

            if ($failure !== null) {
                $reports[] = new StepReport($step->id(), $step->title(), StepStatus::Skipped, $plan);
                continue;
            }
            if (!$plan->hasChanges()) {
                $reports[] = new StepReport($step->id(), $step->title(), StepStatus::Unchanged, $plan);
                continue;
            }
            if ($dryRun) {
                $reports[] = new StepReport($step->id(), $step->title(), StepStatus::Planned, $plan);
                continue;
            }

            try {
                $context->logger->info("Applying \"{$step->id()}\"");
                $context->journal->step($step->id());
                $result = $step->apply($context, $plan);
                $reports[] = new StepReport($step->id(), $step->title(), StepStatus::Applied, $plan, $result);
            } catch (Throwable $e) {
                $failure = $e instanceof StepFailed ? $e->withStepId($step->id()) : StepFailed::in($step->id(), $e);
                $context->logger->error($failure->getMessage());
                $context->journal->note('step failed', ['step' => $step->id(), 'error' => $e->getMessage()]);
                $reports[] = new StepReport($step->id(), $step->title(), StepStatus::Failed, $plan, null, $e->getMessage());
            }
        }

        return new RunResult(
            $this->report($dryRun, $reports, $started, $context),
            $failure === null ? ExitCode::Ok : $this->exitCodeFor($failure),
            $failure,
        );
    }

    private function exitCodeFor(StepFailed $failure): ExitCode
    {
        return $failure instanceof SelfTestFailed ? ExitCode::SelfTestFailed : ExitCode::StepFailed;
    }

    /**
     * @param list<StepReport> $steps
     */
    private function report(bool $dryRun, array $steps, DateTimeImmutable $started, Context $context): Report
    {
        return new Report($dryRun, $steps, $started, new DateTimeImmutable(), $context->journal->path());
    }
}
