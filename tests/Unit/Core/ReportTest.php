<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Core;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Core\Change;
use ThreadSafe\Core\ChangeKind;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\NoticeLevel;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Report;
use ThreadSafe\Core\StepReport;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Core\StepStatus;

final class ReportTest extends TestCase
{
    public function testChangesCarryTheirKindAndMaskSecrets(): void
    {
        $change = Change::update('parameters.php database_password', 'new password', 'old-secret', 'new-secret', secret: true);

        self::assertSame(ChangeKind::Update, $change->kind);
        self::assertSame('***', $change->toArray()['before']);
        self::assertSame('***', $change->toArray()['after']);
        self::assertSame('old-secret', $change->before, 'Only the exported form is masked.');
        self::assertSame(ChangeKind::Create, Change::create('t', 'd')->kind);
        self::assertSame(ChangeKind::Delete, Change::delete('t', 'd')->kind);
    }

    public function testPlanCountsIndividualModifications(): void
    {
        $plan = (new Plan())
            ->add(Change::update('a', 'one'))
            ->add(Change::update('b', 'many', count: 10))
            ->notice(Notice::warning('look at this'));

        self::assertTrue($plan->hasChanges());
        self::assertSame(11, $plan->changeCount());
        self::assertCount(2, $plan->changes());
        self::assertCount(1, $plan->notices());
        self::assertFalse((new Plan())->hasChanges());
        self::assertSame(0, (new Plan())->changeCount());
    }

    private function report(): Report
    {
        $planA = (new Plan())->add(Change::update('a', 'one', 'x', 'y', 3, secret: true))->notice(Notice::warning('careful', 'a'));
        $planB = (new Plan())->notice(Notice::info('fine'));
        $planC = (new Plan())->add(Change::create('c', 'new'));

        return new Report(
            false,
            [
                new StepReport('a', 'Step A', StepStatus::Applied, $planA, new StepResult(3, [Notice::warning('while applying')])),
                new StepReport('b', 'Step B', StepStatus::Unchanged, $planB),
                new StepReport('c', 'Step C', StepStatus::Failed, $planC, null, 'boom'),
            ],
            new DateTimeImmutable('2026-10-20T10:00:00+00:00'),
            new DateTimeImmutable('2026-10-20T10:00:05+00:00'),
            '/tmp/journal.jsonl',
        );
    }

    public function testTotals(): void
    {
        $report = $this->report();

        self::assertSame(4, $report->plannedChanges());
        self::assertSame(3, $report->appliedChanges());
        self::assertSame(2, $report->noticeCount(NoticeLevel::Warning));
        self::assertSame(1, $report->noticeCount(NoticeLevel::Info));
        self::assertTrue($report->hasFailure());
    }

    public function testExportIsJsonAndMasksSecrets(): void
    {
        $json = json_encode($this->report()->toArray(), JSON_THROW_ON_ERROR);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($data);
        self::assertFalse($data['dryRun']);
        self::assertSame('/tmp/journal.jsonl', $data['journal']);
        self::assertSame(2, $data['warnings']);
        self::assertSame('applied', $data['steps'][0]['status']);
        self::assertSame('***', $data['steps'][0]['plan']['changes'][0]['before']);
        self::assertSame('boom', $data['steps'][2]['error']);
        self::assertStringNotContainsString('"x"', $json, 'A secret value must not appear in the export.');
    }
}
