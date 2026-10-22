<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Schedule\DueRun;
use ThreadSafe\Schedule\Scheduler;
use ThreadSafe\Schedule\ScheduleStore;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * When a scheduled backup is due: the latest scheduled instant, the grace period, retries, the order of the queue, the instants
 * before a schedule was set, and daylight saving time.
 */
final class SchedulerTest extends TestCase
{
    use TempDirectory;

    private ScheduleStore $store;

    private string $stateDirectory;

    protected function setUp(): void
    {
        $this->stateDirectory = $this->tempDirectory() . '/schedule';
        $this->store = new ScheduleStore($this->stateDirectory);
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private static function profile(string $id, ?string $cron): SiteProfile
    {
        return SiteProfile::fromArray(['id' => $id, 'type' => 'generic', 'path' => '/var/www/' . $id, 'storage' => '/var/backups/' . $id] + ($cron === null ? [] : ['schedule' => ['cron' => $cron]]));
    }

    private static function at(string $time, string $zone = 'UTC'): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone($zone));
    }

    private function scheduler(string $zone = 'UTC', int $grace = 360): Scheduler
    {
        return new Scheduler($this->store, new DateTimeZone($zone), $grace);
    }

    /**
     * @param list<DueRun> $due
     *
     * @return list<string> "profile@instant" for each
     */
    private static function names(array $due): array
    {
        return array_map(static fn (DueRun $run): string => $run->profileId . '@' . $run->instant->format('Y-m-d H:i'), $due);
    }

    public function testAProfileWithoutAScheduleIsLeftAlone(): void
    {
        $plan = $this->scheduler()->plan([self::profile('shop', null)], self::at('2026-10-22 12:00:00'));

        self::assertSame([], $plan->due);
        self::assertSame([], $plan->skipped);
        self::assertNull($this->store->load('shop'));
    }

    public function testTheScheduleCountsFromTheFirstSightingAndNothingIsMadeUp(): void
    {
        $scheduler = $this->scheduler();
        $shop = [self::profile('shop', '0 3 * * *')];

        // first seen at 05:00: the 03:00 of today lies before, and is within the grace, but nobody asked for it
        self::assertSame([], $scheduler->plan($shop, self::at('2026-10-22 05:00:00'))->due);
        self::assertSame(self::at('2026-10-22 05:00:00')->getTimestamp(), $this->store->load('shop')?->since);

        self::assertSame([], $scheduler->plan($shop, self::at('2026-10-23 02:59:00'))->due);
        self::assertSame(['shop@2026-10-23 03:00'], self::names($scheduler->plan($shop, self::at('2026-10-23 03:00:00'))->due));
    }

    public function testARunIsDueUntilItIsStartedAndNotAgainAfterItIsDone(): void
    {
        $scheduler = $this->scheduler();
        $shop = [self::profile('shop', '0 3 * * *')];
        $scheduler->reset('shop', self::at('2026-10-21 12:00:00'));

        $due = $scheduler->plan($shop, self::at('2026-10-22 03:00:20'))->due;
        self::assertSame(['shop@2026-10-22 03:00'], self::names($due));
        self::assertSame(1, $due[0]->attempt);
        self::assertSame(['shop@2026-10-22 03:00'], self::names($scheduler->plan($shop, self::at('2026-10-22 03:01:00'))->due), 'until it is started');

        $scheduler->started('shop', $due[0]->instant, self::at('2026-10-22 03:01:00'));
        self::assertSame([], $scheduler->plan($shop, self::at('2026-10-22 03:02:00'))->due, 'a run that is going is not started twice');

        $scheduler->succeeded('shop', $due[0]->instant);
        self::assertSame([], $scheduler->plan($shop, self::at('2026-10-22 09:00:00'))->due);
        self::assertSame(['shop@2026-10-23 03:00'], self::names($scheduler->plan($shop, self::at('2026-10-23 03:10:00'))->due), 'the next day it is due again');
    }

    public function testAMissedInstantIsMadeUpWithinTheGraceAndGivenUpAfterIt(): void
    {
        $scheduler = $this->scheduler(grace: 360);
        $shop = [self::profile('shop', '0 3 * * *')];
        $scheduler->reset('shop', self::at('2026-10-21 12:00:00'));

        self::assertSame(['shop@2026-10-22 03:00'], self::names($scheduler->plan($shop, self::at('2026-10-22 08:59:00'))->due), 'five hours and 59 minutes late');

        $plan = $scheduler->plan($shop, self::at('2026-10-22 09:01:00'));
        self::assertSame([], $plan->due);
        self::assertCount(1, $plan->skipped);
        self::assertSame('shop', $plan->skipped[0]->profileId);
        self::assertSame('2026-10-22 03:00', $plan->skipped[0]->instant->format('Y-m-d H:i'));
        self::assertStringContainsString('360 minutes', $plan->skipped[0]->reason);

        $scheduler->skipped('shop', $plan->skipped[0]->instant);
        self::assertSame([], $scheduler->plan($shop, self::at('2026-10-22 09:02:00'))->skipped, 'told once');
    }

    public function testAfterDaysWithoutATriggerExactlyOneRunIsMadeUp(): void
    {
        $scheduler = $this->scheduler();
        $shop = [self::profile('shop', '0 3 * * *')];
        $scheduler->reset('shop', self::at('2026-10-19 12:00:00'));

        $plan = $scheduler->plan($shop, self::at('2026-10-23 05:00:00'));

        self::assertSame(['shop@2026-10-23 03:00'], self::names($plan->due), 'the days in between are not a pile of runs');
        self::assertSame([], $plan->skipped);
    }

    public function testAFailedRunIsTriedAgainTwiceAndThenGivenUp(): void
    {
        $scheduler = $this->scheduler();
        $shop = [self::profile('shop', '0 3 * * *')];
        $scheduler->reset('shop', self::at('2026-10-21 12:00:00'));
        $instant = self::at('2026-10-22 03:00:00');

        $scheduler->started('shop', $instant, self::at('2026-10-22 03:00:10'));
        self::assertTrue($scheduler->failed('shop', $instant, self::at('2026-10-22 03:05:00')));
        self::assertSame([], $scheduler->plan($shop, self::at('2026-10-22 03:19:59'))->due, 'a quarter of an hour later');
        $second = $scheduler->plan($shop, self::at('2026-10-22 03:20:00'))->due;
        self::assertSame(['shop@2026-10-22 03:00'], self::names($second));
        self::assertSame(2, $second[0]->attempt);

        $scheduler->started('shop', $instant, self::at('2026-10-22 03:20:00'));
        self::assertTrue($scheduler->failed('shop', $instant, self::at('2026-10-22 03:30:00')));
        $third = $scheduler->plan($shop, self::at('2026-10-22 03:45:00'))->due;
        self::assertSame(3, $third[0]->attempt);

        $scheduler->started('shop', $instant, self::at('2026-10-22 03:45:00'));
        self::assertFalse($scheduler->failed('shop', $instant, self::at('2026-10-22 03:50:00')), 'the attempts are used up');
        $plan = $scheduler->plan($shop, self::at('2026-10-22 05:00:00'));
        self::assertSame([], $plan->due);
        self::assertSame([], $plan->skipped, 'given up is not skipped: the failure was reported when it happened');

        self::assertSame(['shop@2026-10-23 03:00'], self::names($scheduler->plan($shop, self::at('2026-10-23 03:00:00'))->due), 'the next day starts afresh');
        self::assertSame(1, $scheduler->plan($shop, self::at('2026-10-23 03:00:00'))->due[0]->attempt);
    }

    public function testARetryThatWouldComeAfterTheGraceIsGivenUp(): void
    {
        $scheduler = $this->scheduler(grace: 30);
        $shop = [self::profile('shop', '0 3 * * *')];
        $scheduler->reset('shop', self::at('2026-10-21 12:00:00'));
        $instant = self::at('2026-10-22 03:00:00');
        $scheduler->started('shop', $instant, self::at('2026-10-22 03:00:00'));
        $scheduler->failed('shop', $instant, self::at('2026-10-22 03:20:00'));

        $plan = $scheduler->plan($shop, self::at('2026-10-22 03:35:00'));

        self::assertSame([], $plan->due);
        self::assertCount(1, $plan->skipped);
        self::assertStringContainsString('retries', $plan->skipped[0]->reason);
    }

    public function testTheQueueIsOrderedByTheScheduledInstantThenByProfile(): void
    {
        $scheduler = $this->scheduler();
        $profiles = [self::profile('zeta', '0 3 * * *'), self::profile('alpha', '30 3 * * *'), self::profile('beta', '0 3 * * *'), self::profile('idle', null)];
        foreach ($profiles as $profile) {
            $scheduler->reset($profile->id, self::at('2026-10-21 12:00:00'));
        }

        $due = $scheduler->plan($profiles, self::at('2026-10-22 04:00:00'))->due;

        self::assertSame(['beta@2026-10-22 03:00', 'zeta@2026-10-22 03:00', 'alpha@2026-10-22 03:30'], self::names($due));
    }

    public function testChangingTheScheduleDropsTheInstantsBeforeTheChange(): void
    {
        $scheduler = $this->scheduler();
        $shop = [self::profile('shop', '0 3 * * *')];
        $scheduler->reset('shop', self::at('2026-10-21 12:00:00'));
        self::assertCount(1, $scheduler->plan($shop, self::at('2026-10-22 04:00:00'))->due);

        $scheduler->reset('shop', self::at('2026-10-22 04:30:00'));

        self::assertSame([], $scheduler->plan($shop, self::at('2026-10-22 05:00:00'))->due);
    }

    public function testALostOrDamagedStateIsLikeANewProfile(): void
    {
        $scheduler = $this->scheduler();
        $shop = [self::profile('shop', '0 3 * * *')];
        $scheduler->reset('shop', self::at('2026-10-21 12:00:00'));
        file_put_contents($this->stateDirectory . '/shop.json', '{"since": "yesterday"');

        self::assertNull($this->store->load('shop'));
        self::assertSame([], $scheduler->plan($shop, self::at('2026-10-22 04:00:00'))->due);
        self::assertSame(self::at('2026-10-22 04:00:00')->getTimestamp(), $this->store->load('shop')?->since);
    }

    public function testTheTimeZoneOfTheInstallationDecidesWhatThreeOClockIs(): void
    {
        $scheduler = $this->scheduler('Europe/Berlin');
        $shop = [self::profile('shop', '30 3 * * *')];
        $scheduler->reset('shop', self::at('2026-10-21 12:00:00'));

        self::assertSame([], $scheduler->plan($shop, self::at('2026-10-22 01:29:00'))->due, '03:29 in Berlin');
        $due = $scheduler->plan($shop, self::at('2026-10-22 01:31:00'))->due;
        self::assertSame(['shop@2026-10-22 03:30'], self::names($due));
        self::assertSame('Europe/Berlin', $due[0]->instant->getTimezone()->getName());
    }

    public function testTheSecondPassOfARepeatedHourDoesNotRunAgain(): void
    {
        $scheduler = $this->scheduler('Europe/Berlin');
        $shop = [self::profile('shop', '30 2 * * *')];
        $scheduler->reset('shop', self::at('2026-10-24 12:00:00', 'UTC'));

        $due = $scheduler->plan($shop, self::at('2026-10-25 00:31:00', 'UTC'))->due;   // 02:31 CEST
        self::assertSame(['shop@2026-10-25 02:30'], self::names($due));
        $scheduler->started('shop', $due[0]->instant, self::at('2026-10-25 00:31:00', 'UTC'));
        $scheduler->succeeded('shop', $due[0]->instant);

        self::assertSame([], $scheduler->plan($shop, self::at('2026-10-25 01:31:00', 'UTC'))->due, '02:31 CET, an hour later by the clock on the wall');
    }

    public function testTheNextRunIsShownOnTheClockOfTheInstallation(): void
    {
        $scheduler = $this->scheduler('Europe/Berlin');

        $next = $scheduler->next(self::profile('shop', '30 3 * * *'), self::at('2026-10-22 12:00:00'));

        self::assertSame('2026-10-23 03:30 +02:00', $next?->format('Y-m-d H:i P'));
        self::assertNull($scheduler->next(self::profile('shop', null), self::at('2026-10-22 12:00:00')));
    }

    public function testForgettingRemovesTheState(): void
    {
        $scheduler = $this->scheduler();
        $scheduler->reset('shop', self::at('2026-10-21 12:00:00'));

        $scheduler->forget('shop');

        self::assertNull($this->store->load('shop'));
    }

}
