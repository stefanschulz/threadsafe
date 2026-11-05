<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Engine\Lock;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\RunView;

/**
 * What the page of a run shows while a step is working (field test: long waits in which nothing seemed to happen, buttons that could be
 * pressed at any time).
 */
final class RunViewTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    #[DataProvider('clocks')]
    public function testTheClockReadsLikeAClock(int $seconds, string $expected): void
    {
        self::assertSame($expected, RunView::clock($seconds));
    }

    /** @return iterable<string, array{int, string}> */
    public static function clocks(): iterable
    {
        yield 'nothing' => [0, '0:00'];
        yield 'a few seconds' => [7, '0:07'];
        yield 'minutes' => [125, '2:05'];
        yield 'an hour' => [3600, '1:00:00'];
        yield 'hours' => [3 * 3600 + 61, '3:01:01'];
        yield 'never negative' => [-5, '0:00'];
    }

    public function testTheButtonsAreEnabledOnlyWhereTheyMakeSense(): void
    {
        self::assertStringContainsString('id="go">Continue', RunView::buttons(false));
        self::assertStringContainsString('id="retry" disabled>Try the failed step again', RunView::buttons(false));
        self::assertStringContainsString('id="go" disabled>Continue', RunView::buttons(true));
        self::assertStringContainsString('id="retry">Try the failed step again', RunView::buttons(true));
    }

    public function testTheLiveAnswerNamesTheNoteOnlyWhileAStepHoldsTheLockOfTheRun(): void
    {
        $store = new RunStore($this->tempDirectory() . '/runs');
        $run = $store->create('demo', []);
        $store->saveLive($run->id, microtime(true) - 12.0, 'dumping table ps_product (3 of 299)');

        $idle = RunView::live($store, $run);
        self::assertFalse($idle['working'], 'a live file that nobody holds a lock for is a leftover of a process that died');
        self::assertSame('', $idle['note']);

        $lock = Lock::tryAcquire($store->lockPath($run->id), 'test');
        self::assertNotNull($lock);
        try {
            $working = RunView::live($store, $run);
        } finally {
            $lock->release();
        }
        self::assertTrue($working['working']);
        self::assertSame('dumping table ps_product (3 of 299)', $working['note']);
        self::assertGreaterThanOrEqual(12, $working['seconds']);
        self::assertGreaterThanOrEqual(0, $working['elapsed']);
    }

    public function testAFinishedRunIsNeverWorking(): void
    {
        $store = new RunStore($this->tempDirectory() . '/runs');
        $run = $store->create('demo', []);
        $finished = $run->with(RunStatus::Finished, []);
        $store->saveLive($run->id, microtime(true), 'late note');

        self::assertFalse(RunView::live($store, $finished)['working']);
    }

    public function testThePanelCarriesTheBarTheClockAndTheErrorPlace(): void
    {
        $store = new RunStore($this->tempDirectory() . '/runs');
        $run = $store->create('demo', []);

        $panel = RunView::panel($run->with(RunStatus::Failed, [], 'The disk is full <now>', 'unpacking'), 42, true);

        self::assertStringContainsString('data-percent="42"', $panel);
        self::assertStringContainsString('<p id="status">unpacking</p>', $panel);
        self::assertStringContainsString('All together: 0:0', $panel);
        self::assertStringContainsString('The disk is full &lt;now&gt;', $panel, 'the error is escaped');
    }

    public function testTheScriptHoldsTheButtonsWhileAStepWorksAndAsksForTheNoteOnlyThen(): void
    {
        $script = RunView::script('token"</script>', false, 'restore', 'Rename it.');

        self::assertStringNotContainsString('</script>', $script, 'the key cannot close the script');
        self::assertStringContainsString('function buttons(failed) { go.disabled = cancelling || working || failed; retry.disabled = cancelling || !failed || working; }', $script);
        self::assertStringContainsString('if (working && !cancelling && d.working && d.note)', $script);
        self::assertStringContainsString('"?step=status"', $script);
    }

    public function testTheScriptGivesUpByAskingAgainWhileAStepIsWorkingAndStartsNoNewStep(): void
    {
        $script = RunView::script('token', false, 'backup', 'Allow it.');

        self::assertStringContainsString("document.getElementById('giveup')", $script);
        self::assertStringContainsString("if (d.status === 'busy') { setTimeout(giveUpNow, 1500); return; }", $script);
        self::assertStringContainsString('cancelling = true; stopped = true;', $script, 'no new step after the button');
        self::assertStringContainsString('if (cancelling) { settle(); return; }', $script, 'the answer of the step that was working is not shown as a result');
        self::assertStringContainsString('if (giveUp) {', $script, 'the restore and the installation have no such button');
    }
}
