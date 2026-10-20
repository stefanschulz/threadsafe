<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

use ThreadSafe\Core\Change;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\NoticeLevel;
use ThreadSafe\Core\Report;
use ThreadSafe\Core\StepReport;
use ThreadSafe\Core\StepStatus;

/**
 * Renders a {@see Report} as plain text for the terminal (ASCII only, so it works in every console).
 */
final class TextReportRenderer
{
    private const int VALUE_WIDTH = 100;

    public function render(Report $report, Output $output): void
    {
        $output->writeln($report->dryRun ? 'Dry run – nothing was changed.' : 'Run finished.');
        $output->writeln();

        foreach ($report->steps as $step) {
            $this->renderStep($step, $output);
        }

        $warnings = $report->noticeCount(NoticeLevel::Warning);
        $summary = $report->dryRun
            ? sprintf('%d change(s) planned', $report->plannedChanges())
            : sprintf('%d of %d planned change(s) applied', $report->appliedChanges(), $report->plannedChanges());
        $output->writeln();
        $output->writeln(sprintf('Summary: %s, %d warning(s).', $summary, $warnings));
        if ($report->journalPath !== null) {
            $output->writeln('Journal: ' . $report->journalPath);
        }
    }

    private function renderStep(StepReport $step, Output $output): void
    {
        $marker = match ($step->status) {
            StepStatus::Unchanged => '=',
            StepStatus::Planned => '~',
            StepStatus::Applied => '+',
            StepStatus::Ran => '*',
            StepStatus::Failed => 'x',
            StepStatus::Skipped => '-',
        };
        $detail = match ($step->status) {
            StepStatus::Unchanged => 'already correct',
            StepStatus::Planned => $step->plan->changeCount() . ' change(s) planned',
            StepStatus::Applied => ($step->result->applied ?? 0) . ' change(s) applied',
            StepStatus::Ran => 'done',
            StepStatus::Failed => 'FAILED: ' . $this->clip($step->error ?? ''),
            StepStatus::Skipped => 'skipped',
        };
        $output->writeln(sprintf('  %s %s – %s (%s)', $marker, $step->id, $step->title, $detail));

        foreach ($step->plan->changes() as $change) {
            $output->writeln('      ' . $this->describe($change));
            if ($output->isVerbose() && ($change->before !== null || $change->after !== null)) {
                $output->verbose('          ' . $this->clip($this->mask($change->before, $change)) . '  ->  ' . $this->clip($this->mask($change->after, $change)));
            }
        }
        foreach (array_merge($step->plan->notices(), $step->result->notices ?? []) as $notice) {
            $output->writeln('      ' . $this->describeNotice($notice));
        }
    }

    private function describe(Change $change): string
    {
        $count = $change->count > 1 ? sprintf(' (%d)', $change->count) : '';

        return sprintf('%-6s %s%s: %s', $change->kind->value, $change->target, $count, $change->description);
    }

    private function describeNotice(Notice $notice): string
    {
        $prefix = $notice->level === NoticeLevel::Warning ? '! WARNING' : '  note';

        return $prefix . ($notice->target !== null ? ' [' . $notice->target . ']' : '') . ': ' . $notice->message;
    }

    private function mask(?string $value, Change $change): string
    {
        if ($value === null) {
            return '(none)';
        }

        return $change->secret ? '***' : $value;
    }

    /** Single line, limited length. */
    private function clip(string $value): string
    {
        $value = str_replace(["\r", "\n", "\0"], ['\r', '\n', '\0'], $value);

        return strlen($value) > self::VALUE_WIDTH ? substr($value, 0, self::VALUE_WIDTH - 3) . '...' : $value;
    }
}
