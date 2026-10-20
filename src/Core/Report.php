<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

use DateTimeImmutable;

/**
 * The outcome of a run: one {@see StepReport} per step plus totals. Serialisable to JSON for `--report=`.
 */
final readonly class Report
{
    /**
     * @param list<StepReport> $steps
     */
    public function __construct(
        public bool $dryRun,
        public array $steps,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $finishedAt,
        public ?string $journalPath = null,
    ) {
    }

    /** Individual modifications planned over all steps. */
    public function plannedChanges(): int
    {
        return array_sum(array_map(static fn (StepReport $s): int => $s->plan->changeCount(), $this->steps));
    }

    /** Individual modifications actually performed. */
    public function appliedChanges(): int
    {
        return array_sum(array_map(static fn (StepReport $s): int => $s->result->applied ?? 0, $this->steps));
    }

    public function noticeCount(NoticeLevel $level): int
    {
        $count = 0;
        foreach ($this->steps as $step) {
            $notices = array_merge($step->plan->notices(), $step->result->notices ?? []);
            $count += count(array_filter($notices, static fn (Notice $n): bool => $n->level === $level));
        }

        return $count;
    }

    public function hasFailure(): bool
    {
        foreach ($this->steps as $step) {
            if ($step->status === StepStatus::Failed) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'dryRun' => $this->dryRun,
            'startedAt' => $this->startedAt->format(DATE_ATOM),
            'finishedAt' => $this->finishedAt->format(DATE_ATOM),
            'journal' => $this->journalPath,
            'plannedChanges' => $this->plannedChanges(),
            'appliedChanges' => $this->appliedChanges(),
            'warnings' => $this->noticeCount(NoticeLevel::Warning),
            'steps' => array_map(static fn (StepReport $s): array => $s->toArray(), $this->steps),
        ];
    }
}
