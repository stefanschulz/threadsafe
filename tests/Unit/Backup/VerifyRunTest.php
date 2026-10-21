<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Backup;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Backup\BackupName;
use ThreadSafe\Backup\BackupStatus;
use ThreadSafe\Backup\VerificationReport;
use ThreadSafe\Backup\Verifier;
use ThreadSafe\Backup\VerifyRun;
use ThreadSafe\Backup\VerifyState;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Storage\BackupCatalog;
use ThreadSafe\Storage\LocalStorage;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The check of a backup as a task in ticks: the same findings as in one go, whatever the cuts, and a state that stays small.
 */
final class VerifyRunTest extends TestCase
{
    use TempDirectory;

    private BackupFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new BackupFixture($this->tempDirectory());
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * The check in many small ticks.
     *
     * @return array{VerificationReport, int, list<int|null>} the report, the number of ticks, the percentages on the way
     */
    private function ticked(BackupName $name, bool $full = true, ?LocalStorage $storage = null): array
    {
        $storage ??= $this->fixture->storage();
        $task = new VerifyRun(static fn (): LocalStorage => $storage);
        $state = $task->initialState(['profile' => 'blog', 'backup' => $name->base(), 'full' => $full]);
        $ticks = 0;
        $percentages = [];
        do {
            $outcome = $task->tick($state, new Budget(0.0));
            $state = $outcome->state;
            $percentages[] = VerifyRun::percent($state);
            self::assertLessThan(5000, ++$ticks);
            self::assertLessThan(20000, strlen((string) json_encode($state)), 'the state stays small');
        } while (!$outcome->finished);

        $result = $state['result'];
        self::assertIsArray($result);

        return [VerificationReport::fromArray($result), $ticks, $percentages];
    }

    private function oneGo(BackupName $name, bool $full = true): VerificationReport
    {
        return (new Verifier($this->fixture->storage()))->verify($name, $full);
    }

    public function testAnIntactBackupGivesTheSameReportInOneGoAndInManyTicks(): void
    {
        $name = $this->fixture->backup(['partSize' => 1048576]);

        foreach ([true, false] as $full) {
            $one = $this->oneGo($name, $full);
            [$ticked, $ticks] = $this->ticked($name, $full);

            self::assertSame([], $one->problems);
            self::assertEquals($one->toArray(), $ticked->toArray(), $full ? 'full' : 'quick');
            self::assertGreaterThanOrEqual(2, $ticks, "a budget of nothing cuts even this small check");
            self::assertSame(BackupStatus::Ok, $ticked->status);
        }
    }

    public function testTheBarGoesFromNothingToOneHundredAndNeverBack(): void
    {
        $name = $this->fixture->backup(['partSize' => 1048576]);

        [, , $percentages] = $this->ticked($name);

        $known = array_values(array_filter($percentages, 'is_int'));
        $sorted = $known;
        sort($sorted);
        self::assertSame($sorted, $known);
        self::assertSame(100, end($percentages));
        self::assertLessThan(100, $known[0]);
    }

    public function testADamagedByteIsFoundByBothLevelsAndTheSidecarSaysFaulty(): void
    {
        $name = $this->fixture->backup();
        $part = (new BackupCatalog($this->fixture->storage()))->archiveParts($name)[0];
        $this->fixture->damage($part);

        [$full] = $this->ticked($name, true);
        self::assertFalse($full->ok());
        self::assertSame(BackupStatus::Faulty, $full->status);
        self::assertStringContainsString('SHA-256', implode(' ', $full->problems));
        self::assertStringContainsString('CRC', implode(' ', $full->problems), 'the full level also reads the entries');
        self::assertSame(BackupStatus::Faulty, $this->fixture->storage()->readSidecar($name)->status);

        [$quick] = $this->ticked($name, false);
        self::assertFalse($quick->ok());
        self::assertStringNotContainsString('CRC', implode(' ', $quick->problems), 'the quick level does not open the archive');
        self::assertEquals($this->oneGo($name, true)->problems, $full->problems);
    }

    public function testAMissingPartAnAlienFileAndAWrongSizeAreFound(): void
    {
        $name = $this->fixture->backup(['partSize' => 1048576]);
        $parts = (new BackupCatalog($this->fixture->storage()))->archiveParts($name);
        self::assertGreaterThan(1, count($parts));

        unlink($parts[1]);
        [$missing] = $this->ticked($name);
        self::assertStringContainsString('is missing', implode(' ', $missing->problems));
        self::assertEquals($this->oneGo($name)->problems, $missing->problems);

        $name2 = $this->fixture->backup(['partSize' => 1048576]);
        $parts2 = (new BackupCatalog($this->fixture->storage()))->archiveParts($name2);
        file_put_contents($parts2[0], 'x', FILE_APPEND);
        [$size] = $this->ticked($name2, false);
        self::assertStringContainsString('bytes, the record says', implode(' ', $size->problems));
    }

    public function testASidecarThatIsMissingIsMadeAgainWithANote(): void
    {
        $name = $this->fixture->backup();
        unlink($this->fixture->storage()->sidecarPath($name));

        [$report] = $this->ticked($name);

        self::assertSame([], $report->problems);
        self::assertNotSame([], $report->notes);
        self::assertStringContainsString('made again', $report->notes[0]);
        self::assertFileExists($this->fixture->storage()->sidecarPath($name));
        self::assertSame(BackupStatus::Ok, $this->fixture->storage()->readSidecar($name)->status, 'a full check that passes clears an unverified backup');
    }

    public function testAQuickCheckDoesNotClearABackupThatWasNeverCheckedInFull(): void
    {
        $name = $this->fixture->backup();
        $storage = $this->fixture->storage();
        $storage->writeSidecar($name, $storage->readSidecar($name)->withStatus(BackupStatus::Unverified));

        [$report] = $this->ticked($name, false);

        self::assertSame([], $report->problems);
        self::assertSame(BackupStatus::Unverified, $report->status);
        self::assertSame(BackupStatus::Unverified, $storage->readSidecar($name)->status);
    }

    public function testNothingToFindMeansAReportNotAnException(): void
    {
        $name = BackupName::create('blog', new \DateTimeImmutable('2026-10-21 12:00:00 UTC'), 'abcd1234');

        [$report] = $this->ticked($name);

        self::assertFalse($report->ok());
        self::assertNull($report->status);
        self::assertSame(0, $report->parts);
    }

    public function testTheStateRoundTripsAndRefusesNonsense(): void
    {
        $task = new VerifyRun(fn (): LocalStorage => $this->fixture->storage());
        $state = $task->initialState(['profile' => 'blog', 'backup' => 'blog_20261021-120000_abcd1234']);

        self::assertSame($state, VerifyState::fromArray($state)->toArray());
        $this->expectException(\InvalidArgumentException::class);
        $task->initialState(['profile' => 'blog', 'backup' => '../../x']);
    }

    public function testHostileProblemsAreKeptInNumberAndInSize(): void
    {
        $state = new VerifyState('blog', 'blog_20261021-120000_abcd1234', true);
        for ($i = 0; $i < 250; ++$i) {
            $state->problem("problem $i");
        }

        self::assertCount(VerifyState::MAX_PROBLEMS, $state->problems);
        self::assertSame(150, $state->problemsDropped);
    }
}
