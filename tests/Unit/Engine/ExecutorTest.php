<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Engine;

use Closure;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\EngineException;
use ThreadSafe\Engine\Executor;
use ThreadSafe\Engine\Lock;
use ThreadSafe\Engine\Run;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Engine\Task;
use ThreadSafe\Engine\TickOutcome;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The executor with a task that counts: three units of work per tick, and a budget that runs out after them.
 */
final class ExecutorTest extends TestCase
{
    use TempDirectory;

    private RunStore $store;

    protected function setUp(): void
    {
        $this->store = new RunStore($this->tempDirectory() . '/runs');
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @param Closure(array<string, mixed>): void|null $beforeTick
     */
    private function counting(int $total, int $perTick = 3, ?Closure $beforeTick = null): Task
    {
        return new class ($total, $perTick, $beforeTick) implements Task {
            public function __construct(private readonly int $total, private readonly int $perTick, private readonly ?Closure $beforeTick)
            {
            }

            public function id(): string
            {
                return 'counting';
            }

            public function initialState(array $parameters): array
            {
                return ['done' => 0, 'log' => [], 'label' => $parameters['label'] ?? 'unnamed', 'nested' => ['float' => 0.5, 'list' => [1, 2]]];
            }

            public function tick(array $state, Budget $budget): TickOutcome
            {
                if ($this->beforeTick !== null) {
                    ($this->beforeTick)($state);
                }
                $units = 0;
                while ($state['done'] < $this->total && !($units >= $this->perTick)) {
                    ++$state['done'];
                    ++$units;
                }
                $state['log'][] = $units;

                return new TickOutcome($state, $state['done'] >= $this->total, "{$state['done']} of {$this->total}");
            }
        };
    }

    public function testRunsTickByTickUntilFinished(): void
    {
        $executor = new Executor($this->store, $this->counting(10), static fn (): Budget => Budget::unlimited());
        $run = $executor->start(['label' => 'test']);
        $seen = [];

        $final = $executor->execute($run->id, null, static function (Run $r) use (&$seen): void {
            $seen[] = $r->progress;
        });

        self::assertSame(RunStatus::Finished, $final->status);
        self::assertSame(4, $final->ticks);
        self::assertSame(10, $final->state['done']);
        self::assertSame([3, 3, 3, 1], $final->state['log']);
        self::assertSame(['3 of 10', '6 of 10', '9 of 10', '10 of 10'], $seen);
        self::assertSame('test', $final->state['label']);
        self::assertEquals($final, $this->store->load($run->id), 'What the caller got is what is stored.');
    }

    public function testStateGoesThroughTheStoreBetweenTicks(): void
    {
        $seenStates = [];
        $task = $this->counting(7, 3, function (array $state) use (&$seenStates): void {
            $seenStates[] = $state;
        });
        $executor = new Executor($this->store, $task);

        $executor->execute($executor->start()->id);

        self::assertSame(0.5, $seenStates[2]['nested']['float']);
        self::assertSame([1, 2], $seenStates[2]['nested']['list']);
        self::assertSame([3, 3], $seenStates[2]['log'], 'Each tick starts from what the previous one saved.');
    }

    public function testStopsAfterMaxTicksAndAnotherExecutorContinues(): void
    {
        $first = new Executor($this->store, $this->counting(10));
        $run = $first->start();

        $partial = $first->execute($run->id, 2);

        self::assertSame(RunStatus::Running, $partial->status);
        self::assertSame(6, $partial->state['done']);
        self::assertFalse(Lock::isHeld($this->store->lockPath($run->id)), 'The lock is gone with the execute call.');
        self::assertTrue($this->store->isInterrupted($partial), 'A run left half way is what an interrupted run looks like.');

        $second = new Executor($this->store, $this->counting(10));   // "another process"
        $final = $second->resume($run->id);

        self::assertSame(RunStatus::Finished, $final->status);
        self::assertSame([3, 3, 3, 1], $final->state['log'], 'Same result as an undisturbed run.');
    }

    public function testResumeRefusesARunThatIsBeingExecuted(): void
    {
        $executor = new Executor($this->store, $this->counting(10));
        $run = $executor->start();
        $lock = Lock::tryAcquire($this->store->lockPath($run->id), 'someone else');
        self::assertNotNull($lock);

        foreach ([static fn () => $executor->resume($run->id), static fn () => $executor->execute($run->id)] as $attempt) {
            try {
                $attempt();
                self::fail('A run that is being executed must not be taken over.');
            } catch (EngineException $e) {
                self::assertStringContainsString('another process', $e->getMessage());
            }
        }
        self::assertSame(0, $this->store->load($run->id)->ticks, 'Nothing was done.');
    }

    public function testAFinishedRunStaysFinished(): void
    {
        $executor = new Executor($this->store, $this->counting(2));
        $run = $executor->start();
        $final = $executor->execute($run->id);

        $again = $executor->execute($run->id);

        self::assertSame($final->ticks, $again->ticks, 'No further tick.');
        self::assertSame(RunStatus::Finished, $executor->resume($run->id)->status);
    }

    public function testAFailingTaskMarksTheRunAndPassesTheException(): void
    {
        $calls = 0;
        $task = $this->counting(10, 3, function () use (&$calls): void {
            if (++$calls === 3) {
                throw new RuntimeException('disk full while writing /secret/path');
            }
        });
        $executor = new Executor($this->store, $task);
        $run = $executor->start();

        try {
            $executor->execute($run->id);
            self::fail('The exception must reach the caller.');
        } catch (RuntimeException $e) {
            self::assertSame('disk full while writing /secret/path', $e->getMessage());
        }

        $stored = $this->store->load($run->id);
        self::assertSame(RunStatus::Failed, $stored->status);
        self::assertSame('RuntimeException: disk full while writing /secret/path', $stored->error);
        self::assertSame(6, $stored->state['done'], 'The state of the last good tick is kept for diagnosis.');
        self::assertFalse(Lock::isHeld($this->store->lockPath($run->id)));

        $this->expectException(EngineException::class);
        $this->expectExceptionMessage('has failed');
        $executor->execute($run->id);
    }

    public function testAStateThatCannotBeStoredFailsTheRun(): void
    {
        $task = new class () implements Task {
            public function id(): string
            {
                return 'bad';
            }

            public function initialState(array $parameters): array
            {
                return [];
            }

            public function tick(array $state, Budget $budget): TickOutcome
            {
                return new TickOutcome(['handle' => NAN], true);
            }
        };
        $executor = new Executor($this->store, $task);
        $run = $executor->start();

        try {
            $executor->execute($run->id);
            self::fail('A state that is not JSON is a bug of the task.');
        } catch (EngineException $e) {
            self::assertStringContainsString('cannot be stored as JSON', $e->getMessage());
        }

        self::assertSame(RunStatus::Failed, $this->store->load($run->id)->status);
    }

    public function testARunBelongsToItsTask(): void
    {
        $run = (new Executor($this->store, $this->counting(2)))->start();
        $other = new class () implements Task {
            public function id(): string
            {
                return 'other';
            }

            public function initialState(array $parameters): array
            {
                return [];
            }

            public function tick(array $state, Budget $budget): TickOutcome
            {
                throw new LogicException('must not run');
            }
        };

        $this->expectException(EngineException::class);
        $this->expectExceptionMessage('belongs to the task "counting"');

        (new Executor($this->store, $other))->execute($run->id);
    }

    public function testEveryTickGetsAFreshBudget(): void
    {
        $budgets = 0;
        $executor = new Executor($this->store, $this->counting(7), static function () use (&$budgets): Budget {
            ++$budgets;

            return Budget::unlimited();
        });

        $executor->execute($executor->start()->id);

        self::assertSame(3, $budgets);
    }
}
