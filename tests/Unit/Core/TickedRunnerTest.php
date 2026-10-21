<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\Exception\SelfTestFailed;
use ThreadSafe\Core\ExitCode;
use ThreadSafe\Core\Guard;
use ThreadSafe\Core\NullLogger;
use ThreadSafe\Core\Report;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepStatus;
use ThreadSafe\Core\TickedRunner;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Tests\Support\EventLog;
use ThreadSafe\Tests\Support\FakeAlwaysRunsStep;
use ThreadSafe\Tests\Support\FakeFindingsStep;
use ThreadSafe\Tests\Support\FakeResumableStep;
use ThreadSafe\Tests\Support\FakeStep;
use ThreadSafe\Tests\Support\RecordingJournal;

final class TickedRunnerTest extends TestCase
{
    private EventLog $log;

    private RecordingJournal $journal;

    protected function setUp(): void
    {
        $this->log = new EventLog();
        $this->journal = new RecordingJournal();
    }

    /**
     * Runs to the end the way a restore does: every tick is a new process - new runner, new steps, new context - and the state
     * goes through JSON.
     *
     * @param callable(): list<Step> $steps
     * @param list<Guard>            $guards
     *
     * @return array{array<string, mixed>, int, TickedRunner} the final state, the number of ticks
     */
    private function runToEnd(callable $steps, bool $tickPerStep, array $guards = []): array
    {
        $state = (new TickedRunner($steps(), $guards))->initialState();
        for ($tick = 1; $tick <= 100; ++$tick) {
            $runner = new TickedRunner($steps(), $guards);
            $outcome = $runner->tick(
                json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
                new Context(new stdClass(), $this->journal, new NullLogger(), false),
                $tickPerStep ? new Budget(0.0) : Budget::unlimited(),
            );
            $state = $outcome->state;
            if ($outcome->finished) {
                return [$state, $tick, $runner];
            }
        }

        self::fail('The run did not finish.');
    }

    public function testAllStepsRunInOneTickWithAnUnlimitedBudget(): void
    {
        [$state, $ticks, $runner] = $this->runToEnd(fn (): array => [new FakeStep('a', $this->log), new FakeStep('b', $this->log)], false);

        self::assertSame(1, $ticks);
        self::assertSame(['plan:a', 'plan:b', 'apply:a', 'apply:b'], $this->log->events, 'all plans first, then the applying, as the Runner does');
        self::assertSame(ExitCode::Ok, $runner->exitCode($state));
        $report = $runner->report($state);
        self::assertSame([StepStatus::Applied, StepStatus::Applied], array_map(static fn ($s): StepStatus => $s->status, $report->steps));
        self::assertSame(2, $report->appliedChanges());
        self::assertSame(['a', 'b'], $this->journal->steps);
    }

    public function testATickEndsBetweenStepsWhenTheBudgetIsUsedUp(): void
    {
        [$state, $ticks, $runner] = $this->runToEnd(fn (): array => [new FakeStep('a', $this->log), new FakeStep('b', $this->log), new FakeStep('c', $this->log)], true);

        self::assertSame(3, $ticks, 'one step per tick');
        self::assertSame(['plan:a', 'plan:b', 'plan:c', 'apply:a', 'apply:b', 'apply:c'], $this->log->events, 'planned once, in the first tick');
        self::assertSame(ExitCode::Ok, $runner->exitCode($state));
        self::assertCount(3, $runner->report($state)->steps);
    }

    public function testAResumableStepWorksInTicksAndTheBriefPlanIsUsed(): void
    {
        [$state, $ticks, $runner] = $this->runToEnd(fn (): array => [new FakeStep('a', $this->log), new FakeResumableStep('big', $this->log, 4), new FakeStep('z', $this->log)], true);

        self::assertSame(['plan:a', 'brief:big', 'plan:z', 'apply:a', 'tick:big:1', 'tick:big:2', 'tick:big:3', 'tick:big:4', 'apply:z'], $this->log->events);
        self::assertSame(6, $ticks, 'a, four ticks of the big step (the last one also finishes it), then z');
        $report = $runner->report($state);
        self::assertSame('as it turned out', $report->steps[1]->plan->changes()[0]->description, 'the report shows the plan the step ended with');
        self::assertSame(4, $report->steps[1]->result?->applied);
        self::assertSame(['a', 'big', 'big', 'big', 'big', 'z'], $this->journal->steps, 'the journal is told again in every tick that continues a step');
    }

    public function testFindingsSurviveTheNextProcess(): void
    {
        $world = ['value' => 'source.example'];
        $seen = [];
        $reader = new FakeFindingsStep('read', $this->log, $world);
        $user = new FakeStep('use', $this->log, 1, null, function (Context $context) use (&$seen): void {
            $seen[] = $context->get('found');
        });

        // the first tick plans and applies "read" only; then the installation changes; the second tick must still know the old value
        $runner = new TickedRunner([$reader, $user]);
        $outcome = $runner->tick($runner->initialState(), new Context(new stdClass(), $this->journal, new NullLogger(), false), new Budget(0.0));
        self::assertFalse($outcome->finished);
        $world['value'] = 'target.example';

        $runner = new TickedRunner([new FakeFindingsStep('read', $this->log, $world), $user]);
        $outcome = $runner->tick(json_decode(json_encode($outcome->state, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR), new Context(new stdClass(), $this->journal, new NullLogger(), false), new Budget(0.0));

        self::assertTrue($outcome->finished);
        self::assertSame(['source.example'], $seen);
        self::assertSame(['plan:read', 'export:read', 'import:read'], array_values(array_filter($this->log->events, static fn (string $e): bool => str_ends_with($e, ':read'))), 'planned and exported once, imported in the next process');
    }

    public function testAStepWithoutChangesIsUnchangedAndAlwaysRunsStepsRun(): void
    {
        [$state, , $runner] = $this->runToEnd(fn (): array => [new FakeStep('done-already', $this->log, 0), new FakeAlwaysRunsStep('check', $this->log)], false);

        self::assertSame([StepStatus::Unchanged, StepStatus::Ran], array_map(static fn ($s): StepStatus => $s->status, $runner->report($state)->steps));
        self::assertSame(['plan:done-already', 'plan:check', 'apply:check'], $this->log->events);
    }

    public function testAFailingStepEndsTheRunAndTheRestIsSkipped(): void
    {
        [$state, , $runner] = $this->runToEnd(fn (): array => [
            new FakeStep('a', $this->log),
            new FakeStep('boom', $this->log, 1, null, static function (): void {
                throw new RuntimeException('disk full');
            }),
            new FakeStep('c', $this->log),
        ], true);

        self::assertSame(ExitCode::StepFailed, $runner->exitCode($state));
        $report = $runner->report($state);
        self::assertSame([StepStatus::Applied, StepStatus::Failed, StepStatus::Skipped], array_map(static fn ($s): StepStatus => $s->status, $report->steps));
        self::assertSame('disk full', $report->steps[1]->error);
        self::assertTrue($report->hasFailure());
        self::assertNotContains('apply:c', $this->log->events);
        self::assertSame('step failed', $this->journal->notes[0]);
    }

    public function testAFailedSelfTestHasItsOwnExitCode(): void
    {
        [$state, , $runner] = $this->runToEnd(fn (): array => [
            new FakeAlwaysRunsStep('selftest', $this->log, static function (): void {
                throw new SelfTestFailed('the home page answers 500');
            }),
        ], false);

        self::assertSame(ExitCode::SelfTestFailed, $runner->exitCode($state));
    }

    public function testAGuardEndsTheRunBeforeAnythingIsPlanned(): void
    {
        $guard = new class () implements Guard {
            public function id(): string
            {
                return 'g';
            }

            public function check(Context $context): string
            {
                return 'this is the wrong site';
            }
        };

        [$state, $ticks, $runner] = $this->runToEnd(fn (): array => [new FakeStep('a', $this->log)], false, [$guard]);

        self::assertSame(1, $ticks);
        self::assertSame(ExitCode::GuardFailed, $runner->exitCode($state));
        self::assertSame([], $this->log->events);
        self::assertSame('this is the wrong site', $state['failure']['message']);
        self::assertSame([], $runner->report($state)->steps);
    }

    public function testAGuardFailedFromAPlanEndsTheRunToo(): void
    {
        [$state, , $runner] = $this->runToEnd(fn (): array => [
            new FakeStep('read', $this->log, 1, static function (): void {
                throw new GuardFailed('not a PrestaShop');
            }),
            new FakeStep('b', $this->log),
        ], false);

        self::assertSame(ExitCode::GuardFailed, $runner->exitCode($state));
        self::assertSame(['plan:read'], $this->log->events);
    }

    public function testTheReportOfTheStateIsTheSameAsTheJsonOfTheFinishedRun(): void
    {
        [$state, , $runner] = $this->runToEnd(fn (): array => [new FakeStep('a', $this->log), new FakeResumableStep('big', $this->log, 2)], true);

        $report = $runner->report($state);
        $again = Report::fromArray($report->toArray());

        self::assertSame($report->toArray(), $again->toArray());
        self::assertFalse($report->dryRun);
    }

    public function testProgressNamesTheStep(): void
    {
        $steps = [new FakeStep('a', $this->log), new FakeResumableStep('big', $this->log, 3)];
        $runner = new TickedRunner($steps);
        $context = new Context(new stdClass(), $this->journal, new NullLogger(), false);

        $first = $runner->tick($runner->initialState(), $context, new Budget(0.0));
        self::assertStringContainsString('Step big', (string) $first->progress);

        $second = $runner->tick($first->state, $context, new Budget(0.0));
        self::assertStringContainsString('working 1 of 3', (string) $second->progress);
    }
}
