<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\Exception\SelfTestFailed;
use ThreadSafe\Core\Exception\StepFailed;
use ThreadSafe\Core\ExitCode;
use ThreadSafe\Core\Guard;
use ThreadSafe\Core\NullJournal;
use ThreadSafe\Core\NullLogger;
use ThreadSafe\Core\Runner;
use ThreadSafe\Core\StepStatus;
use ThreadSafe\Tests\Support\EventLog;
use ThreadSafe\Tests\Support\FakeAlwaysRunsStep;
use ThreadSafe\Tests\Support\FakeStep;
use ThreadSafe\Tests\Support\RecordingJournal;

final class RunnerTest extends TestCase
{
    private EventLog $log;

    private RecordingJournal $journal;

    protected function setUp(): void
    {
        $this->log = new EventLog();
        $this->journal = new RecordingJournal();
    }

    private function context(bool $dryRun = false): Context
    {
        return new Context(new stdClass(), $dryRun ? new NullJournal() : $this->journal, new NullLogger(), $dryRun);
    }

    private function guard(?string $problem): Guard
    {
        return new class ($problem, $this->log) implements Guard {
            public function __construct(private readonly ?string $problem, private readonly EventLog $log)
            {
            }

            public function id(): string
            {
                return 'test-guard';
            }

            public function check(Context $context): ?string
            {
                $this->log->add('guard');

                return $this->problem;
            }
        };
    }

    /**
     * @param list<StepStatus> $expected
     */
    private function assertStatuses(array $expected, \ThreadSafe\Core\RunResult $result): void
    {
        self::assertSame(
            array_map(static fn (StepStatus $s): string => $s->value, $expected),
            array_map(static fn ($step): string => $step->status->value, $result->report->steps),
        );
    }

    public function testAlwaysRunsStepRunsWithoutChangesOnAnAppliedRun(): void
    {
        $runner = new Runner([new FakeStep('a', $this->log, 0), new FakeAlwaysRunsStep('check', $this->log)]);

        $result = $runner->run($this->context(), apply: true);

        self::assertSame(['plan:a', 'plan:check', 'apply:check'], $this->log->events);
        $this->assertStatuses([StepStatus::Unchanged, StepStatus::Ran], $result);
        self::assertSame([], $this->journal->steps, 'A step that changes nothing leaves no trace in the journal.');
        self::assertTrue($result->isOk());
    }

    public function testAlwaysRunsStepDoesNotRunInADryRun(): void
    {
        $runner = new Runner([new FakeAlwaysRunsStep('check', $this->log)]);

        $result = $runner->run($this->context(dryRun: true), apply: false);

        self::assertSame(['plan:check'], $this->log->events);
        $this->assertStatuses([StepStatus::Unchanged], $result);
    }

    public function testFailingAlwaysRunsStepGivesTheSelfTestExitCode(): void
    {
        $check = new FakeAlwaysRunsStep('self-test', $this->log, static function (): never {
            throw new SelfTestFailed('home page answers 500');
        });

        $result = (new Runner([new FakeStep('a', $this->log), $check]))->run($this->context(), apply: true);

        self::assertSame(ExitCode::SelfTestFailed, $result->exitCode);
        $this->assertStatuses([StepStatus::Applied, StepStatus::Failed], $result);
    }

    public function testDryRunPlansEverythingAndAppliesNothing(): void
    {
        $runner = new Runner([new FakeStep('a', $this->log, 2), new FakeStep('b', $this->log, 1)]);

        $result = $runner->run($this->context(dryRun: true), apply: false);

        self::assertSame(['plan:a', 'plan:b'], $this->log->events);
        self::assertTrue($result->isOk());
        self::assertTrue($result->report->dryRun);
        self::assertSame(3, $result->report->plannedChanges());
        self::assertSame(0, $result->report->appliedChanges());
        $this->assertStatuses([StepStatus::Planned, StepStatus::Planned], $result);
    }

    public function testAllPlansAreComputedBeforeAnythingIsApplied(): void
    {
        $runner = new Runner([new FakeStep('a', $this->log), new FakeStep('b', $this->log), new FakeStep('c', $this->log)]);

        $result = $runner->run($this->context(), apply: true);

        self::assertSame(['plan:a', 'plan:b', 'plan:c', 'apply:a', 'apply:b', 'apply:c'], $this->log->events);
        self::assertSame(ExitCode::Ok, $result->exitCode);
        self::assertFalse($result->report->dryRun);
        self::assertSame(3, $result->report->appliedChanges());
        $this->assertStatuses([StepStatus::Applied, StepStatus::Applied, StepStatus::Applied], $result);
    }

    public function testStepsWithoutChangesAreNotApplied(): void
    {
        $runner = new Runner([new FakeStep('a', $this->log, 0), new FakeStep('b', $this->log, 1)]);

        $result = $runner->run($this->context(), apply: true);

        self::assertSame(['plan:a', 'plan:b', 'apply:b'], $this->log->events);
        $this->assertStatuses([StepStatus::Unchanged, StepStatus::Applied], $result);
        self::assertSame(['b'], $this->journal->steps, 'Only steps that run are marked in the journal.');
    }

    public function testDryRunNeverTouchesTheJournal(): void
    {
        $runner = new Runner([new FakeStep('a', $this->log)]);

        $runner->run($this->context(), apply: false);

        self::assertSame([], $this->journal->steps);
    }

    public function testViolatedGuardStopsBeforePlanning(): void
    {
        $runner = new Runner([new FakeStep('a', $this->log)], [$this->guard('wrong site')]);

        $result = $runner->run($this->context(), apply: true);

        self::assertSame(['guard'], $this->log->events);
        self::assertSame(ExitCode::GuardFailed, $result->exitCode);
        self::assertInstanceOf(GuardFailed::class, $result->failure);
        self::assertSame('wrong site', $result->failure->getMessage());
        self::assertSame([], $result->report->steps);
    }

    public function testSatisfiedGuardsLetTheRunContinue(): void
    {
        $runner = new Runner([new FakeStep('a', $this->log)], [$this->guard(null), $this->guard(null)]);

        $result = $runner->run($this->context(), apply: true);

        self::assertSame(['guard', 'guard', 'plan:a', 'apply:a'], $this->log->events);
        self::assertTrue($result->isOk());
    }

    public function testStepCanRefuseTheRunWhilePlanning(): void
    {
        $refusing = new FakeStep('a', $this->log, onPlan: static function (): never {
            throw new GuardFailed('domain not found');
        });
        $runner = new Runner([$refusing, new FakeStep('b', $this->log)]);

        $result = $runner->run($this->context(), apply: true);

        self::assertSame(ExitCode::GuardFailed, $result->exitCode);
        self::assertSame(['plan:a'], $this->log->events);
    }

    public function testPlanningFailureAppliesNothing(): void
    {
        $broken = new FakeStep('b', $this->log, onPlan: static function (): never {
            throw new RuntimeException('cannot read');
        });
        $runner = new Runner([new FakeStep('a', $this->log), $broken]);

        $result = $runner->run($this->context(), apply: true);

        self::assertSame(ExitCode::StepFailed, $result->exitCode);
        self::assertSame(['plan:a', 'plan:b'], $this->log->events, 'Nothing may be applied when a plan cannot be made.');
        self::assertInstanceOf(StepFailed::class, $result->failure);
        self::assertSame('b', $result->failure->stepId());
    }

    public function testFailingStepStopsTheRunAndKeepsEarlierStepsReported(): void
    {
        $failing = new FakeStep('b', $this->log, onApply: static function (): never {
            throw new RuntimeException('disk full');
        });
        $runner = new Runner([new FakeStep('a', $this->log), $failing, new FakeStep('c', $this->log)]);

        $result = $runner->run($this->context(), apply: true);

        self::assertSame(['plan:a', 'plan:b', 'plan:c', 'apply:a', 'apply:b'], $this->log->events);
        self::assertSame(ExitCode::StepFailed, $result->exitCode);
        $this->assertStatuses([StepStatus::Applied, StepStatus::Failed, StepStatus::Skipped], $result);
        self::assertInstanceOf(StepFailed::class, $result->failure);
        self::assertSame('b', $result->failure->stepId());
        self::assertStringContainsString('disk full', $result->failure->getMessage());
        self::assertSame('disk full', $result->report->steps[1]->error);
        self::assertTrue($result->report->hasFailure());
        self::assertSame(['step failed'], $this->journal->notes);
    }

    public function testStepFailedKeepsItsOwnMessageAndGetsTheStepId(): void
    {
        $failing = new FakeStep('a', $this->log, onApply: static function (): never {
            throw new StepFailed('custom message');
        });

        $result = (new Runner([$failing]))->run($this->context(), apply: true);

        self::assertInstanceOf(StepFailed::class, $result->failure);
        self::assertSame('custom message', $result->failure->getMessage());
        self::assertSame('a', $result->failure->stepId());
    }

    public function testFailedSelfTestHasItsOwnExitCode(): void
    {
        $selfTest = new FakeStep('self-test', $this->log, onApply: static function (): never {
            throw new SelfTestFailed('home page answers 500');
        });

        $result = (new Runner([new FakeStep('a', $this->log), $selfTest]))->run($this->context(), apply: true);

        self::assertSame(ExitCode::SelfTestFailed, $result->exitCode);
        $this->assertStatuses([StepStatus::Applied, StepStatus::Failed], $result);
    }

    public function testStepsShareDiscoveredStateThroughTheContext(): void
    {
        $reader = new FakeStep('read', $this->log, onPlan: static function (Context $context): void {
            $context->set('domain', 'shop.example');
        });
        $user = new FakeStep('use', $this->log, onPlan: function (Context $context): void {
            $this->log->add('saw:' . $context->get('domain'));
        });

        (new Runner([$reader, $user]))->run($this->context(dryRun: true), apply: false);

        self::assertContains('saw:shop.example', $this->log->events);
    }
}
