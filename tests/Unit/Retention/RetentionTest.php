<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Retention;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Retention\Retention;
use ThreadSafe\Retention\RetentionAction;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\FakeBackups;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * Which backups stay: every rule alone and together, what is never deleted, the order, the numbers after each deletion, the dry run,
 * leftovers, and a storage that cannot be read.
 */
final class RetentionTest extends TestCase
{
    use TempDirectory;

    private const string NOW = '2026-10-22 12:00:00';

    private BackupFixture $fixture;

    private FakeBackups $fake;

    protected function setUp(): void
    {
        $this->fixture = new BackupFixture($this->tempDirectory());
        $this->fake = new FakeBackups($this->fixture);
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @param (\Closure(string): bool)|null $claimed
     */
    private function retention(?\Closure $claimed = null): Retention
    {
        return new Retention($this->fixture->storage(), $claimed, fn (): int => (new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')))->getTimestamp());
    }

    /** @param array<string, int> $rules */
    private function profile(array $rules): SiteProfile
    {
        return $this->fixture->profiles->load('blog')->with(['retention' => $rules]);
    }

    /** days before NOW */
    private static function daysAgo(int $days): string
    {
        return (new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')))->modify("-$days days")->format('Y-m-d H:i:s');
    }

    /** @return list<string> the base names a plan would delete */
    private static function names(\ThreadSafe\Retention\RetentionPlan $plan): array
    {
        return array_map(static fn (RetentionAction $a): string => $a->base(), $plan->delete);
    }

    public function testWithoutRulesNothingHappens(): void
    {
        $this->fake->make(self::daysAgo(1));
        $plan = $this->retention()->plan($this->fixture->profiles->load('blog'));

        self::assertTrue($plan->isEmpty());
        self::assertStringContainsString('no retention rules', $plan->notes[0]);
    }

    public function testKeepDeletesTheOldestBeyondTheNewestN(): void
    {
        $backups = [];
        foreach ([1, 2, 3, 4, 5] as $days) {
            $backups[$days] = $this->fake->make(self::daysAgo($days));
        }

        $plan = $this->retention()->plan($this->profile(['keep' => 3]));

        self::assertSame([$backups[5]->base(), $backups[4]->base()], self::names($plan), 'the oldest first');
        self::assertSame(RetentionAction::KEEP, $plan->delete[0]->rule);
        self::assertStringContainsString('keep 3', $plan->delete[0]->reason);
        self::assertSame(5, $plan->usable);
        self::assertSame(50_000_000, $plan->bytes);
        self::assertSame(20_000_000, $plan->freed());
        self::assertTrue($this->fake->exists($backups[5]), 'a plan deletes nothing');
    }

    public function testApplyDeletesExactlyWhatThePlanSaid(): void
    {
        $backups = [];
        foreach ([1, 2, 3, 4, 5, 6] as $days) {
            $backups[$days] = $this->fake->make(self::daysAgo($days));
        }
        $profile = $this->profile(['keep' => 2]);
        $planned = self::names($this->retention()->plan($profile));

        $seen = [];
        $done = $this->retention()->apply($profile, static function (RetentionAction $a) use (&$seen): void {
            $seen[] = $a->base();
        });

        self::assertSame($planned, self::names($done), 'the dry run and the real thing agree');
        self::assertSame($planned, $seen, 'every deletion was announced, in order');
        foreach ([1, 2] as $days) {
            self::assertTrue($this->fake->exists($backups[$days]));
        }
        foreach ([3, 4, 5, 6] as $days) {
            self::assertFalse($this->fake->exists($backups[$days]), "$days days old");
        }
        self::assertSame([], glob($this->fixture->storageDirectory . '/*' . $backups[6]->base() . '*') ?: [], 'parts and sidecar file are gone');
        self::assertSame(2, $done->usable);
    }

    public function testMaxAgeDaysDeletesTheOlderOnes(): void
    {
        $fresh = $this->fake->make(self::daysAgo(1));
        $ten = $this->fake->make(self::daysAgo(10));
        $forty = $this->fake->make(self::daysAgo(40));
        $hundred = $this->fake->make(self::daysAgo(100));

        $plan = $this->retention()->plan($this->profile(['maxAgeDays' => 30]));

        self::assertSame([$hundred->base(), $forty->base()], self::names($plan));
        self::assertSame(RetentionAction::AGE, $plan->delete[0]->rule);
        self::assertStringContainsString('older than 30 days (100 days old)', $plan->delete[0]->reason);
        self::assertTrue($this->fake->exists($fresh) && $this->fake->exists($ten));
    }

    public function testMaxBytesDeletesTheOldestUntilTheRestFits(): void
    {
        foreach ([1, 2, 3, 4, 5] as $days) {
            $this->fake->make(self::daysAgo($days));
        }

        $plan = $this->retention()->plan($this->profile(['maxBytes' => 25_000_000]));

        self::assertCount(3, $plan->delete, '50 MB, then 40, 30, 20: three go');
        self::assertSame(RetentionAction::SIZE, $plan->delete[0]->rule);
        self::assertStringContainsString('over the limit of', $plan->delete[0]->reason);
        self::assertSame([], $plan->notes === [] ? [] : array_filter($plan->notes, static fn (string $n): bool => str_contains($n, 'cannot be met')), 'the limit can be met');
    }

    public function testTheRulesWorkTogetherAndTheNumbersAreRecomputedAfterEachDeletion(): void
    {
        foreach ([1, 2, 3, 40, 50, 60] as $days) {
            $this->fake->make(self::daysAgo($days));
        }

        // the age rule takes 60, 50, 40; then only three remain, so keep 3 has nothing left to do
        $plan = $this->retention()->plan($this->profile(['keep' => 3, 'maxAgeDays' => 30]));
        self::assertCount(3, $plan->delete);
        self::assertSame([RetentionAction::KEEP, RetentionAction::KEEP, RetentionAction::KEEP], array_map(static fn (RetentionAction $a): string => $a->rule, $plan->delete), 'keep 3 asks for the three oldest first; the age rule then has nothing left');

        // with keep 5 the age rule is the one that deletes
        $plan = $this->retention()->plan($this->profile(['keep' => 5, 'maxAgeDays' => 45]));
        self::assertCount(2, $plan->delete);
        self::assertSame(RetentionAction::KEEP, $plan->delete[0]->rule, 'the oldest is beyond the newest five');
        self::assertSame(RetentionAction::AGE, $plan->delete[1]->rule, 'and after it, 50 days is beyond 45');
    }

    public function testTheNewestUsableBackupIsNeverDeletedWhateverTheRulesSay(): void
    {
        $only = $this->fake->make(self::daysAgo(200));

        $plan = $this->retention()->plan($this->profile(['maxAgeDays' => 30, 'keep' => 1, 'maxBytes' => 1_048_576]));

        self::assertSame([], $plan->delete);
        self::assertTrue($this->fake->exists($only));
        self::assertStringContainsString('cannot be met', implode(' ', $plan->notes));
        self::assertStringContainsString('protected', implode(' ', $plan->notes));
    }

    public function testAFaultyOrIncompleteBackupIsLeftAloneAndTheNewestUsableOneStaysEvenWhenAFaultyOneIsNewer(): void
    {
        $good = $this->fake->make(self::daysAgo(60));
        $faulty = $this->fake->make(self::daysAgo(1), status: 'faulty');
        $incomplete = $this->fake->make(self::daysAgo(2));
        unlink($this->fixture->storageDirectory . '/' . $incomplete->partFile(1, false));   // a part is missing

        $plan = $this->retention()->plan($this->profile(['keep' => 1, 'maxAgeDays' => 30]));

        self::assertSame([], $plan->delete, 'the good one is the newest usable one: it stays');
        self::assertTrue($this->fake->exists($faulty) && $this->fake->exists($good));
        self::assertStringContainsString('2 backups are faulty, incomplete or unreadable and left alone', implode(' ', $plan->notes));
        self::assertSame(1, $plan->usable);
    }

    public function testAPinnedBackupStaysAndDoesNotCountTowardsKeep(): void
    {
        $newest = $this->fake->make(self::daysAgo(1));
        $second = $this->fake->make(self::daysAgo(2));
        $third = $this->fake->make(self::daysAgo(3));
        $pinned = $this->fake->make(self::daysAgo(400), pinned: true);

        $plan = $this->retention()->plan($this->profile(['keep' => 2, 'maxAgeDays' => 30]));

        self::assertSame([$third->base()], self::names($plan), 'keep 2 counts the unpinned ones; the old pinned one is neither counted nor deleted');
        self::assertTrue($this->fake->exists($pinned));
        self::assertTrue($this->fake->exists($newest) && $this->fake->exists($second));
        self::assertStringContainsString('pinned', implode(' ', $plan->notes));
    }

    public function testAPinnedBackupCountsForTheSizeButIsNotDeletedForIt(): void
    {
        $this->fake->make(self::daysAgo(1), 10_000_000);
        $this->fake->make(self::daysAgo(2), 10_000_000);
        $pinned = $this->fake->make(self::daysAgo(3), 40_000_000, pinned: true);

        $plan = $this->retention()->plan($this->profile(['maxBytes' => 30_000_000]));

        self::assertCount(1, $plan->delete, 'the older of the two small ones goes; the pinned one stays and the newest too');
        self::assertTrue($this->fake->exists($pinned));
        self::assertStringContainsString('cannot be met', implode(' ', $plan->notes));
    }

    public function testABackupThatARunUsesIsLeftAlone(): void
    {
        $new = $this->fake->make(self::daysAgo(1));
        $old = $this->fake->make(self::daysAgo(100));
        $older = $this->fake->make(self::daysAgo(200));

        $plan = $this->retention(static fn (string $base): bool => $base === $old->base())->plan($this->profile(['maxAgeDays' => 30]));

        self::assertSame([$older->base()], self::names($plan));
        self::assertTrue($this->fake->exists($new) && $this->fake->exists($old));
    }

    public function testABackupThatIsPinnedBetweenThePlanAndTheDeletionStays(): void
    {
        foreach ([1, 2, 3, 4] as $days) {
            $backups[$days] = $this->fake->make(self::daysAgo($days));
        }
        $profile = $this->profile(['keep' => 1]);
        $storage = $this->fixture->storage();

        $done = $this->retention()->apply($profile, function (RetentionAction $first) use ($storage, $backups): void {
            // after the first deletion somebody pins the next one that was planned
            $next = $first->base() === $backups[4]->base() ? $backups[3] : null;
            if ($next !== null) {
                $storage->writeSidecar($next, $storage->readSidecar($next)->withPinned(true));
            }
        });

        self::assertSame([$backups[4]->base(), $backups[2]->base()], self::names($done));
        self::assertTrue($this->fake->exists($backups[3]), 'pinned meanwhile');
        self::assertFalse($this->fake->exists($backups[2]), 'the rest of the plan went on');
        self::assertStringContainsString('was left', implode(' ', $done->notes));
    }

    public function testLeftoversOfDeadRunsGoWhenOldAndUnclaimed(): void
    {
        $this->fake->make(self::daysAgo(1));
        $old = $this->fake->leftover(self::daysAgo(10));
        $young = $this->fake->leftover(self::daysAgo(2));
        $claimed = $this->fake->leftover(self::daysAgo(20));

        $retention = $this->retention(static fn (string $base): bool => $base === $claimed->base());
        $plan = $retention->plan($this->profile(['keep' => 5]));

        self::assertSame([], $plan->delete);
        self::assertSame([$old->base()], array_map(static fn (RetentionAction $a): string => $a->base(), $plan->leftovers));
        self::assertSame(RetentionAction::LEFTOVER, $plan->leftovers[0]->rule);
        self::assertStringContainsString('last touched 10 days ago', $plan->leftovers[0]->reason);

        $retention->apply($this->profile(['keep' => 5]));
        self::assertFalse($this->fake->exists($old));
        self::assertTrue($this->fake->exists($young), 'a young one may only be slow');
        self::assertTrue($this->fake->exists($claimed), 'and a claimed one is a run that is going');
    }

    public function testAStorageThatCannotBeReadDeletesNothing(): void
    {
        $profile = $this->fixture->profiles->load('blog')->with(['retention' => ['keep' => 1], 'storage' => $this->fixture->storageDirectory . '-gone']);

        $plan = (new Retention(new \ThreadSafe\Storage\LocalStorage($profile->storage)))->apply($profile);

        self::assertTrue($plan->blocked);
        self::assertSame([], $plan->delete);
        self::assertStringContainsString('cannot be read', $plan->notes[0]);
    }
}
