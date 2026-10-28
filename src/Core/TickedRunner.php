<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

use DateTimeImmutable;
use DateTimeZone;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\Exception\SelfTestFailed;
use ThreadSafe\Core\Exception\StepFailed;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\TickOutcome;
use Throwable;

/**
 * The {@see Runner} for a run that has to continue in another process: on a web host every request is a tick of a few seconds,
 * and a step like the content replacement in a big database takes longer than that.
 *
 * It applies and does not plan for show (there is no dry run): the first tick checks the guards and plans all steps, as the
 * Runner does before it applies anything, and keeps in its state everything the later ticks need - the plans, the findings
 * of the steps that look at the installation ({@see HasFindings}), the reports so far, the position and the state of the step in
 * progress ({@see ResumableStep}) - as plain data. A step that is not resumable is applied whole inside a tick; one that was cut
 * off by a crash is simply applied again on resume (steps are idempotent). A tick ends between two steps once the budget is
 * used up, or inside a resumable step.
 *
 * The caller makes the {@see Context} of every tick; it must carry the same request and a journal that continues the file
 * named in the state's `journal` ({@see FileJournal}'s `$resume`).
 *
 * State: `stage` (`start`, `apply`, `done`), `next`, `stepState`, `plans`, `findings`, `reports`, `startedAt`, `journal`,
 * `progress` (what the step in progress said), `failure` (null, or `kind` guard / step / selftest, `step`, `message`).
 */
final class TickedRunner
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

    /**
     * @return array<string, mixed>
     */
    public function initialState(): array
    {
        return [
            'stage' => 'start',
            'next' => 0,
            'stepState' => null,
            'progress' => null,
            'plans' => [],
            'findings' => [],
            'reports' => [],
            'startedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            'finishedAt' => null,
            'journal' => null,
            'failure' => null,
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function tick(array $state, Context $context, Budget $budget): TickOutcome
    {
        $state = $this->work($state, $context, $budget);
        if ($state['stage'] === 'done' && $state['finishedAt'] === null) {
            $state['finishedAt'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
        }
        $state['journal'] = $context->journal->path() ?? $state['journal'];
        $done = $state['stage'] === 'done';

        return new TickOutcome($state, $done, $done ? $this->summary($state) : $this->progress($state));
    }

    /**
     * The report of a run so far, or of the finished run.
     *
     * @param array<string, mixed> $state
     */
    public function report(array $state): Report
    {
        $steps = [];
        $known = [];
        /** @var list<array<string, mixed>> $reports */
        $reports = $state['reports'];
        foreach ($reports as $entry) {
            $steps[] = StepReport::fromArray($entry);
            $known[(string) $entry['id']] = true;
        }
        if ($state['stage'] === 'done' && ($reports !== [] || (int) $state['next'] > 0)) {
            foreach ($this->steps as $step) {   // after a failure the steps that did not run are reported as skipped
                if (!isset($known[$step->id()])) {
                    $plan = isset($state['plans'][$step->id()]) ? Plan::fromArray((array) $state['plans'][$step->id()]) : new Plan();
                    $steps[] = new StepReport($step->id(), $step->title(), StepStatus::Skipped, $plan);
                }
            }
        }
        $started = new DateTimeImmutable((string) $state['startedAt']);

        return new Report(false, $steps, $started, new DateTimeImmutable((string) ($state['finishedAt'] ?? 'now')), is_string($state['journal']) ? $state['journal'] : null);
    }

    /**
     * @param array<string, mixed> $state
     */
    public function exitCode(array $state): ExitCode
    {
        $failure = $state['failure'] ?? null;
        if (!is_array($failure)) {
            return ExitCode::Ok;
        }

        return match ($failure['kind']) {
            'guard' => ExitCode::GuardFailed,
            'selftest' => ExitCode::SelfTestFailed,
            default => ExitCode::StepFailed,
        };
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function work(array $state, Context $context, Budget $budget): array
    {
        if ($state['stage'] === 'done') {
            return $state;
        }

        if ($state['stage'] === 'start') {
            $state = $this->start($state, $context);
            if ($state['stage'] === 'done') {
                return $state;
            }
        } else {
            foreach ($this->steps as $step) {
                if ($step instanceof HasFindings && isset($state['findings'][$step->id()])) {
                    try {
                        $step->importFindings($context, (array) $state['findings'][$step->id()]);
                    } catch (Throwable $e) {
                        return $this->fail($state, $context, 'step', $step, 'Cannot continue: ' . $e->getMessage());
                    }
                }
            }
        }

        $worked = false;
        for ($i = (int) $state['next']; $i < count($this->steps); $i = (int) $state['next']) {
            if ($worked && $budget->exhausted()) {
                return $state;
            }
            $worked = true;
            $step = $this->steps[$i];
            $budget->note(sprintf('adapting the site (step %d of %d): %s', $i + 1, count($this->steps), $step->title()));
            $plan = Plan::fromArray((array) ($state['plans'][$step->id()] ?? []));

            if (!$plan->hasChanges() && !$step instanceof AlwaysRuns) {
                $state['reports'][] = (new StepReport($step->id(), $step->title(), StepStatus::Unchanged, $plan))->toArray();
                $state['next'] = $i + 1;
                continue;
            }

            try {
                $context->logger->info("Applying \"{$step->id()}\"");
                if ($plan->hasChanges()) {
                    $context->journal->step($step->id());
                }
                if ($step instanceof ResumableStep) {
                    $tick = $step->applyTick($context, $budget, (array) ($state['stepState'] ?? []));
                    if (!$tick->finished) {
                        $state['stepState'] = $tick->state;
                        $state['progress'] = $tick->progress ?? $step->title();

                        return $state;
                    }
                    $result = $tick->result ?? new StepResult(0);
                    $plan = $tick->plan ?? $plan;
                } else {
                    $result = $step->apply($context, $plan);
                }
                $state['stepState'] = null;
                $state['reports'][] = (new StepReport($step->id(), $step->title(), $plan->hasChanges() ? StepStatus::Applied : StepStatus::Ran, $plan, $result))->toArray();
                $state['next'] = $i + 1;
            } catch (Throwable $e) {
                return $this->fail($state, $context, $e instanceof SelfTestFailed ? 'selftest' : 'step', $step, $e->getMessage(), $plan);
            }
        }

        $state['stage'] = 'done';

        return $state;
    }

    /**
     * The first tick: guards, then the plans of all steps (and the findings of the steps that look at the installation).
     *
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function start(array $state, Context $context): array
    {
        foreach ($this->guards as $guard) {
            $problem = $guard->check($context);
            if ($problem !== null) {
                $context->logger->error("Guard \"{$guard->id()}\" refused the run: $problem");
                $state['failure'] = ['kind' => 'guard', 'step' => null, 'message' => $problem];
                $state['stage'] = 'done';

                return $state;
            }
        }

        foreach ($this->steps as $step) {
            try {
                $context->logger->debug("Planning \"{$step->id()}\"");
                $plan = $step instanceof ResumableStep ? $step->planBrief($context) : $step->plan($context);
                $state['plans'][$step->id()] = $plan->toArray();
                if ($step instanceof HasFindings) {
                    $state['findings'][$step->id()] = $step->exportFindings($context);
                }
            } catch (GuardFailed $e) {
                $state['failure'] = ['kind' => 'guard', 'step' => $step->id(), 'message' => $e->getMessage()];
                $state['stage'] = 'done';

                return $state;
            } catch (Throwable $e) {
                $state['failure'] = ['kind' => $e instanceof SelfTestFailed ? 'selftest' : 'step', 'step' => $step->id(), 'message' => $e->getMessage()];
                $state['stage'] = 'done';

                return $state;
            }
        }
        $state['stage'] = 'apply';

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function fail(array $state, Context $context, string $kind, Step $step, string $message, ?Plan $plan = null): array
    {
        $failure = StepFailed::in($step->id(), new \RuntimeException($message));
        $context->logger->error($failure->getMessage());
        $context->journal->note('step failed', ['step' => $step->id(), 'error' => $message]);
        $state['reports'][] = (new StepReport($step->id(), $step->title(), StepStatus::Failed, $plan ?? new Plan(), null, $message))->toArray();
        $state['failure'] = ['kind' => $kind, 'step' => $step->id(), 'message' => $failure->getMessage()];
        $state['stepState'] = null;
        $state['next'] = (int) $state['next'] + 1;
        $state['stage'] = 'done';

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function progress(array $state): string
    {
        if ($state['stage'] === 'start') {
            return 'checking the installation';
        }
        $index = min((int) $state['next'], count($this->steps) - 1);

        return sprintf(
            'adapting the installation: %s (step %d of %d)',
            is_string($state['progress'] ?? null) && $state['stepState'] !== null ? $state['progress'] : $this->steps[$index]->title(),
            $index + 1,
            count($this->steps),
        );
    }

    /**
     * @param array<string, mixed> $state
     */
    private function summary(array $state): string
    {
        $failure = $state['failure'] ?? null;
        if (is_array($failure)) {
            return 'adapting the installation failed: ' . $failure['message'];
        }

        return sprintf('adapted the installation (%d steps)', count($this->steps));
    }
}
