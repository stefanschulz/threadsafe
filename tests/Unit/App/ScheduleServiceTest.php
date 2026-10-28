<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\App;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ThreadSafe\App\ProfileService;
use ThreadSafe\App\ScheduleChange;
use ThreadSafe\App\ScheduleRow;
use ThreadSafe\App\ScheduleService;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\ScheduleSettings;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Schedule\Scheduler;
use ThreadSafe\Schedule\ScheduleState;
use ThreadSafe\Schedule\ScheduleStore;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The table of all schedules and the changes of several at once (docs/PHASE8.md 3.3, 3.4).
 */
final class ScheduleServiceTest extends TestCase
{
    use TempDirectory;

    private string $base;

    private ProfileStore $store;

    private ScheduleStore $states;

    private ScheduleService $service;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->base = $this->tempDirectory();
        mkdir($this->base . '/site', 0777, true);
        $zone = new DateTimeZone('UTC');
        $this->now = new DateTimeImmutable('2026-10-28 12:00:00', $zone);
        $this->store = new ProfileStore($this->base . '/data/profiles');
        $this->states = new ScheduleStore($this->base . '/data/schedule');
        $plugins = PluginRegistry::load();
        $this->service = new ScheduleService(new ProfileService($this->store, $plugins, $this->base . '/data'), new Scheduler($this->states, $zone), $this->states, $plugins);
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @param array<string, mixed>|null $schedule
     */
    private function add(string $id, ?array $schedule, string $type = 'generic', string $name = ''): void
    {
        $this->store->save(SiteProfile::fromArray(['id' => $id, 'name' => $name !== '' ? $name : $id, 'type' => $type, 'path' => $this->base . '/site', 'storage' => $this->base . '/storage/' . $id, 'note' => 'note of ' . $id] + ($schedule === null ? [] : ['schedule' => $schedule])));
    }

    /**
     * @param list<ScheduleRow> $rows
     *
     * @return list<string>
     */
    private static function ids(array $rows): array
    {
        return array_map(static fn (ScheduleRow $row): string => $row->profile->id, $rows);
    }

    private function fill(): void
    {
        $this->add('zeta', ['every' => 'day', 'at' => '03:00'], 'generic', 'Zeta shop');
        $this->add('alpha', ['every' => 'day', 'at' => '14:30'], 'prestashop9', 'alpha shop');
        $this->add('beta', ['every' => 'hours', 'interval' => 6, 'minute' => 15], 'generic', 'Beta');
        $this->add('gamma', ['every' => 'week', 'on' => ['mon'], 'at' => '01:00', 'paused' => true], 'joomla', 'Gamma');
        $this->add('delta', null, 'generic', 'Delta');
    }

    public function testTheRowsCarryTheTypeTitleTheScheduleAndTheNextRun(): void
    {
        $this->fill();
        $this->states->save('zeta', new ScheduleState(1000, 2000, ScheduleState::DONE, 1));

        $rows = array_column(array_map(static fn (ScheduleRow $row): array => [$row->profile->id, $row], $this->service->rows($this->now)), 1, 0);

        self::assertSame('Other website', $rows['zeta']->typeTitle);
        self::assertSame('PrestaShop 9', $rows['alpha']->typeTitle);
        self::assertSame('2026-10-29 03:00', $rows['zeta']->next?->format('Y-m-d H:i'));
        self::assertSame('2026-10-28 12:15', $rows['beta']->next?->format('Y-m-d H:i'), 'every six hours at :15');
        self::assertNull($rows['gamma']->next, 'paused: no next run');
        self::assertTrue($rows['gamma']->paused());
        self::assertNull($rows['delta']->next);
        self::assertNull($rows['delta']->schedule);
        self::assertSame(ScheduleState::DONE, $rows['zeta']->last?->status);
        self::assertNull($rows['alpha']->last);
    }

    public function testSortedByTimeTheProfilesThatWillRunComeFirstAndThePausedAndUnscheduledOnesLast(): void
    {
        $this->fill();

        self::assertSame(['beta', 'alpha', 'zeta', 'gamma', 'delta'], self::ids($this->service->rows($this->now, 'time', 'asc')), 'earliest first, then paused, then unscheduled');
        self::assertSame(['zeta', 'alpha', 'beta', 'gamma', 'delta'], self::ids($this->service->rows($this->now, 'time', 'desc')), 'latest first, the others stay behind');
    }

    public function testSortedByNameUpperAndLowerCaseAlike(): void
    {
        $this->fill();

        self::assertSame(['alpha', 'beta', 'delta', 'gamma', 'zeta'], self::ids($this->service->rows($this->now, 'name', 'asc')));
        self::assertSame(['zeta', 'gamma', 'delta', 'beta', 'alpha'], self::ids($this->service->rows($this->now, 'name', 'desc')));
    }

    public function testSortedByTypeWithTheNameBreakingTies(): void
    {
        $this->fill();

        $ascending = $this->service->rows($this->now, 'type', 'asc');

        self::assertSame(['gamma', 'beta', 'delta', 'zeta', 'alpha'], self::ids($ascending), 'Joomla, then the other websites by name, then PrestaShop');
        self::assertSame(['alpha', 'beta', 'delta', 'zeta', 'gamma'], self::ids($this->service->rows($this->now, 'type', 'desc')), 'the types turn round, the names in a type do not');
    }

    public function testAnUnknownSortIsTheSortByTime(): void
    {
        $this->fill();

        self::assertSame(self::ids($this->service->rows($this->now, 'time')), self::ids($this->service->rows($this->now, 'nonsense', 'sideways')));
    }

    public function testSchedulesThatStartCloseTogetherNameEachOther(): void
    {
        $this->add('one', ['every' => 'day', 'at' => '03:00']);
        $this->add('two', ['every' => 'day', 'at' => '03:05']);
        $this->add('three', ['every' => 'day', 'at' => '03:10']);
        $this->add('paused', ['every' => 'day', 'at' => '03:01', 'paused' => true]);
        $this->add('none', null);

        $rows = array_column(array_map(static fn (ScheduleRow $row): array => [$row->profile->id, $row], $this->service->rows($this->now)), 1, 0);

        self::assertSame(['two'], $rows['one']->overlaps, 'ten minutes apart is not close');
        self::assertSame(['one', 'three'], $rows['two']->overlaps);
        self::assertSame(['two'], $rows['three']->overlaps);
        self::assertSame([], $rows['paused']->overlaps, 'a paused schedule runs nothing');
        self::assertSame([], $rows['none']->overlaps);
    }

    public function testSettingAScheduleForSeveralProfilesWritesOnlyWhatChangesAndKeepsTheRest(): void
    {
        $this->add('a', null);
        $this->add('b', ['every' => 'day', 'at' => '03:00']);
        $this->add('c', ['every' => 'day', 'at' => '04:00']);
        $this->states->save('b', new ScheduleState(1000, 2000, ScheduleState::DONE, 1));
        $schedule = ScheduleSettings::daily(3, 0);

        $changes = $this->service->set(['a', 'b', 'c', 'nope'], $schedule);

        self::assertSame(
            [ScheduleChange::CHANGED, ScheduleChange::UNCHANGED, ScheduleChange::CHANGED, ScheduleChange::MISSING],
            array_map(static fn (ScheduleChange $change): string => $change->status, $changes),
        );
        self::assertSame('03:00', $this->store->load('a')->schedule?->toArray()['at']);
        self::assertSame('03:00', $this->store->load('c')->schedule?->toArray()['at']);
        self::assertSame('note of a', $this->store->load('a')->note, 'what else the profile has stays');
        self::assertEquals(new ScheduleState(1000, 2000, ScheduleState::DONE, 1), $this->states->load('b'), 'a schedule that did not change does not start counting again');
        self::assertEqualsWithDelta(time(), $this->states->load('a')?->since, 5, 'a new schedule counts from now');
        self::assertSame($schedule->toArray(), $changes[0]->after?->toArray());
        self::assertNull($changes[0]->before);
    }

    public function testASchedulePutsTheOldPausedFlagAwayWhenItIsSetAnew(): void
    {
        $this->add('a', ['every' => 'day', 'at' => '03:00', 'paused' => true]);

        $this->service->set(['a'], ScheduleSettings::daily(3, 0));

        self::assertFalse($this->store->load('a')->schedule?->paused, 'the settings that were given are the ones that count, paused or not');
    }

    public function testNoScheduleTakesTheScheduleAndItsStateAway(): void
    {
        $this->add('a', ['every' => 'day', 'at' => '03:00']);
        $this->states->save('a', new ScheduleState(1000));

        $changes = $this->service->set(['a'], null);

        self::assertSame(ScheduleChange::CHANGED, $changes[0]->status);
        self::assertNull($this->store->load('a')->schedule);
        self::assertNull($this->states->load('a'));
    }

    public function testPausingAndResumingKeepTheSettingsAndSkipWhatHasNoSchedule(): void
    {
        $this->add('a', ['every' => 'week', 'on' => ['mon', 'thu'], 'at' => '02:15']);
        $this->add('b', null);

        $paused = $this->service->pause(['a', 'b', 'nope'], true);

        self::assertSame([ScheduleChange::CHANGED, ScheduleChange::NOTHING, ScheduleChange::MISSING], array_map(static fn (ScheduleChange $change): string => $change->status, $paused));
        self::assertTrue($this->store->load('a')->schedule?->paused);
        self::assertSame('15 2 * * mon,thu', $this->store->load('a')->schedule?->cron());
        self::assertSame(ScheduleChange::UNCHANGED, $this->service->pause(['a'], true)[0]->status, 'already paused');

        $resumed = $this->service->pause(['a'], false);

        self::assertSame(ScheduleChange::CHANGED, $resumed[0]->status);
        self::assertFalse($this->store->load('a')->schedule?->paused);
        self::assertEqualsWithDelta(time(), $this->states->load('a')?->since, 5, 'resumed: it counts from now');
    }
}
