<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Core;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Core\Change;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Report;
use ThreadSafe\Core\StepReport;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Core\StepStatus;

/**
 * A report can be written as data and read back (a run in ticks keeps it in its state file).
 */
final class ReportRoundTripTest extends TestCase
{
    public function testAReportComesBackAsItWas(): void
    {
        $plan = (new Plan())
            ->add(Change::update('db ps_cms.content', 'replace the old domain', 'http://old', 'http://new', 12))
            ->add(Change::create('file x', 'create', 'content', 1, true))
            ->add(Change::delete('file y', 'delete', 'before'))
            ->notice(Notice::warning('careful', 'ps_cms'))
            ->notice(Notice::info('fyi'));
        $report = new Report(false, [
            new StepReport('a', 'Step A', StepStatus::Applied, $plan, new StepResult(14, [Notice::info('done', 'x')])),
            new StepReport('b', 'Step B', StepStatus::Failed, new Plan(), null, 'it broke'),
            new StepReport('c', 'Step C', StepStatus::Skipped, new Plan()),
        ], new DateTimeImmutable('2026-10-21T10:00:00+00:00'), new DateTimeImmutable('2026-10-21T10:05:00+00:00'), '/var/journal.jsonl');

        $again = Report::fromArray(json_decode(json_encode($report->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));

        self::assertSame($report->toArray(), $again->toArray());
        self::assertSame(14, $again->appliedChanges());
        self::assertTrue($again->hasFailure());
        self::assertSame('***', $again->steps[0]->plan->changes()[1]->after, 'a secret stays masked');
    }
}
