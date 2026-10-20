<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Cli;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Cli\BufferedOutput;
use ThreadSafe\Cli\TextReportRenderer;
use ThreadSafe\Core\Change;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Report;
use ThreadSafe\Core\StepReport;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Core\StepStatus;

final class TextReportRendererTest extends TestCase
{
    /**
     * @param list<StepReport> $steps
     */
    private function report(bool $dryRun, array $steps, ?string $journal = null): Report
    {
        $now = new DateTimeImmutable('2026-10-20T10:00:00+00:00');

        return new Report($dryRun, $steps, $now, $now, $journal);
    }

    private function render(Report $report, bool $verbose = false): string
    {
        $output = new BufferedOutput(verbose: $verbose);
        (new TextReportRenderer())->render($report, $output);

        return $output->stdout();
    }

    public function testDryRunReport(): void
    {
        $plan = (new Plan())
            ->add(Change::update('db ps_configuration PS_SHOP_DOMAIN', 'new domain', 'old.example', 'new.example'))
            ->add(Change::update('db ps_cms_lang content', 'replace URLs', count: 10))
            ->notice(Notice::warning('bare domain left untouched', 'ps_cms_lang'));
        $report = $this->report(true, [
            new StepReport('config', 'Update configuration', StepStatus::Planned, $plan),
            new StepReport('caches', 'Discard caches', StepStatus::Unchanged, new Plan()),
        ]);

        $text = $this->render($report);

        self::assertStringContainsString('Dry run – nothing was changed.', $text);
        self::assertStringContainsString('~ config – Update configuration (11 change(s) planned)', $text);
        self::assertStringContainsString('= caches – Discard caches (already correct)', $text);
        self::assertStringContainsString('update db ps_cms_lang content (10): replace URLs', $text);
        self::assertStringContainsString('! WARNING [ps_cms_lang]: bare domain left untouched', $text);
        self::assertStringContainsString('Summary: 11 change(s) planned, 1 warning(s).', $text);
        self::assertStringNotContainsString('Journal:', $text);
    }

    public function testAppliedRunWithFailureAndJournal(): void
    {
        $plan = (new Plan())->add(Change::update('file x', 'rewrite'));
        $report = $this->report(false, [
            new StepReport('a', 'Step A', StepStatus::Applied, $plan, new StepResult(1, [Notice::info('done')])),
            new StepReport('b', 'Step B', StepStatus::Failed, $plan, null, "disk\nfull"),
            new StepReport('c', 'Step C', StepStatus::Skipped, $plan),
        ], '/data/journal.jsonl');

        $text = $this->render($report);

        self::assertStringContainsString('Run finished.', $text);
        self::assertStringContainsString('+ a – Step A (1 change(s) applied)', $text);
        self::assertStringContainsString('x b – Step B (FAILED: disk\nfull)', $text);
        self::assertStringContainsString('- c – Step C (skipped)', $text);
        self::assertStringContainsString('note: done', $text);
        self::assertStringContainsString('Summary: 1 of 3 planned change(s) applied', $text);
        self::assertStringContainsString('Journal: /data/journal.jsonl', $text);
    }

    public function testVerboseShowsValuesButMasksSecrets(): void
    {
        $plan = (new Plan())
            ->add(Change::update('parameters.php database_host', 'host', 'db', 'target-db'))
            ->add(Change::update('parameters.php database_password', 'password', 'old-secret', 'new-secret', secret: true));
        $report = $this->report(true, [new StepReport('p', 'Parameters', StepStatus::Planned, $plan)]);

        $quiet = $this->render($report);
        $verbose = $this->render($report, verbose: true);

        self::assertStringNotContainsString('target-db  ', $quiet);
        self::assertStringContainsString('db  ->  target-db', $verbose);
        self::assertStringContainsString('***  ->  ***', $verbose);
        self::assertStringNotContainsString('old-secret', $verbose);
        self::assertStringNotContainsString('new-secret', $verbose);
    }

    public function testLongValuesAreClippedToOneLine(): void
    {
        $plan = (new Plan())->add(Change::update('db t c', 'long', str_repeat('a', 300) . "\nsecond line", 'short'));
        $report = $this->report(true, [new StepReport('p', 'P', StepStatus::Planned, $plan)]);

        $text = $this->render($report, verbose: true);

        self::assertStringContainsString(str_repeat('a', 97) . '...', $text);
        self::assertStringNotContainsString("\nsecond line", $text);
    }
}
