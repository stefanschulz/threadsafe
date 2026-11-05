<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Web\Wizard;

/**
 * Field test: the done page of the restore wizard showed the warnings of the preflight, but not those of the adaptation, so that a value that
 * kept the old domain (too long for its column) was reported on the command line only.
 */
final class WizardAdaptationWarningsTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $notices
     * @param list<array<string, mixed>> $resultNotices
     *
     * @return array<string, mixed>
     */
    private static function report(array $notices, array $resultNotices = []): array
    {
        return ['steps' => [
            ['id' => 'replace-content-domains', 'title' => 'Replace the old domain in database content', 'status' => 'applied', 'plan' => ['changes' => [], 'notices' => $notices], 'result' => ['applied' => 3, 'notices' => $resultNotices]],
            ['id' => 'other', 'title' => 'Another step', 'status' => 'applied', 'plan' => ['changes' => [], 'notices' => []], 'result' => null],
        ]];
    }

    public function testTheWarningsOfTheStepsAreListedWithTheirTitleAndTarget(): void
    {
        $lines = Wizard::adaptationWarnings(self::report([
            ['level' => 'warning', 'message' => '2 value(s) would be too long for the column with the new domain and keep the old one.', 'target' => 'jos_redirect_links.old_url'],
            ['level' => 'info', 'message' => 'left untouched on purpose', 'target' => null],
        ], [['level' => 'warning', 'message' => 'Something at the end', 'target' => null]]));

        self::assertSame([
            'Replace the old domain in database content: jos_redirect_links.old_url: 2 value(s) would be too long for the column with the new domain and keep the old one.',
            'Replace the old domain in database content: Something at the end',
        ], $lines);
    }

    public function testTheSameWarningIsListedOnceAndAManyAreCut(): void
    {
        $same = ['level' => 'warning', 'message' => 'x', 'target' => 't'];
        self::assertCount(1, Wizard::adaptationWarnings(self::report([$same, $same])));

        $many = [];
        for ($i = 0; $i < 40; ++$i) {
            $many[] = ['level' => 'warning', 'message' => "w$i", 'target' => null];
        }
        $lines = Wizard::adaptationWarnings(self::report($many));
        self::assertCount(31, $lines);
        self::assertStringContainsString('10 more', $lines[30]);
    }

    public function testNoReportGivesNoWarnings(): void
    {
        self::assertSame([], Wizard::adaptationWarnings([]));
    }
}
