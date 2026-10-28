<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Admin\AuditLog;
use ThreadSafe\Tests\Support\AdminBrowser;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The page of all schedules in the browser (docs/PHASE8.md 3.3, 3.4): the table and its order, the form for one and for several profiles, pause
 * and resume, and what happens to a request that is wrong or has no right to be.
 */
final class SchedulePagesTest extends TestCase
{
    use TempDirectory;

    private BackupFixture $fixture;

    private AdminBrowser $browser;

    protected function setUp(): void
    {
        $this->fixture = new BackupFixture($this->tempDirectory());
        $this->browser = new AdminBrowser($this->fixture->data);
        $this->browser->now = time();
        $this->browser->install();
        $this->browser->login();
        $this->browser->get('/');
        $this->add('blog', 'My Blog', ['every' => 'day', 'at' => '03:30']);
        $this->add('shop', 'Shop', ['every' => 'week', 'on' => ['mon', 'thu'], 'at' => '02:15']);
        $this->add('wiki', 'Wiki', null);
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @param array<string, mixed>|null $schedule
     */
    private function add(string $id, string $name, ?array $schedule): void
    {
        $base = $this->fixture->profiles->load('blog');
        $this->fixture->profiles->save($base->with(['id' => $id, 'name' => $name, 'storage' => $base->storage . '-' . $id, 'schedule' => $schedule]));
    }

    /** @return array<string, mixed>|null */
    private function schedule(string $id): ?array
    {
        return $this->fixture->profiles->load($id)->schedule?->toArray();
    }

    private function flashes(): string
    {
        return $this->browser->get('/schedule')->body;
    }

    public function testThePageIsInTheMenuAndShowsOneLineForEveryProfileInWords(): void
    {
        $page = $this->browser->get('/schedule');

        self::assertSame(200, $page->status);
        self::assertMatchesRegularExpression('~<a href="[^"]*/schedule" title="Schedule" aria-current="page">~', $page->body, 'the entry of the menu is the current one');
        self::assertStringContainsString('Every day at 03:30', $page->body);
        self::assertStringContainsString('Every Monday and Thursday at 02:15', $page->body);
        self::assertStringContainsString('Not scheduled', $page->body);
        self::assertStringContainsString('Times are in the time zone <strong>' . date_default_timezone_get() . '</strong>', $page->body);
        foreach (['blog', 'shop', 'wiki'] as $id) {
            self::assertStringContainsString('name="p_' . $id . '" value="1"', $page->body, 'every line can be ticked');
        }
        self::assertStringContainsString('Other website', $page->body, 'the type as people know it');
        self::assertMatchesRegularExpression('~<span class="when" title="[^"]+">[A-Z][a-z]{2} \d{1,2} [A-Z][a-z]{2} \d\d:\d\d</span> <span class="hint">\(in ~', $page->body, 'the next run, and how far away it is');
    }

    public function testTheHeadsOfTheColumnsSortAndSayWhichWayTheTableIsSorted(): void
    {
        $page = $this->browser->get('/schedule');

        // by default: by the time of the next run, earliest first
        self::assertStringContainsString('<th scope="col" aria-sort="ascending"><a class="sort" href="', $page->body);
        self::assertMatchesRegularExpression('~<th scope="col" aria-sort="none"><a class="sort" href="[^"]*sort=name&amp;dir=asc">Profile</a></th>~', $page->body);
        self::assertMatchesRegularExpression('~sort=time&amp;dir=desc">Runs~', $page->body, 'pressing the sorted column turns it round');

        $byName = $this->browser->get('/schedule?sort=name&dir=desc')->body;
        $wiki = (int) strpos($byName, '>Wiki<');
        $shop = (int) strpos($byName, '>Shop<');
        $blog = (int) strpos($byName, '>My Blog<');
        self::assertTrue($wiki > 0 && $wiki < $shop && $shop < $blog, 'names, the last one first');
        self::assertStringContainsString('aria-sort="descending"><a class="sort" href="', $byName);
    }

    public function testSortedByTimeThePausedAndUnscheduledProfilesComeAfterThoseThatWillRun(): void
    {
        $this->browser->post('/schedule/action', ['do' => 'pause:blog']);

        foreach (['/schedule', '/schedule?sort=time&dir=desc'] as $route) {
            $body = $this->browser->get($route)->body;
            $shop = (int) strpos($body, '>Shop<');
            self::assertGreaterThan($shop, (int) strpos($body, '>My Blog<'), $route . ': the paused one is behind the one that runs');
            self::assertGreaterThan((int) strpos($body, '>My Blog<'), (int) strpos($body, '>Wiki<'), $route . ': and the unscheduled one behind both');
        }
    }

    public function testTheFilterByTypeKeepsTheSortAndShowsOnlyThatType(): void
    {
        $this->fixture->profiles->save($this->fixture->profiles->load('shop')->with(['type' => 'prestashop9']));

        $page = $this->browser->get('/schedule?type=prestashop9&sort=name');

        self::assertStringContainsString('>Shop<', $page->body);
        self::assertStringNotContainsString('>My Blog<', $page->body);
        self::assertStringContainsString('name="type" value="prestashop9"', $page->body, 'a change goes back to the same view');
        preg_match('~href="([^"]*sort=name[^"]*)"~', $page->body, $link);
        self::assertStringContainsString('type=prestashop9', $link[1] ?? '', 'the sort links keep the filter');
    }

    public function testASingleProfileHasAFormThatStartsWithItsSchedule(): void
    {
        $page = $this->browser->get('/schedule/edit?profile=shop');

        self::assertSame(200, $page->status);
        self::assertStringContainsString('The schedule of <strong>Shop</strong>', $page->body);
        self::assertStringContainsString('<option value="week" selected>On chosen days of the week</option>', $page->body);
        self::assertStringContainsString('name="schedule_on_mon" value="1" checked', $page->body);
        self::assertStringContainsString('name="schedule_on_thu" value="1" checked', $page->body);
        self::assertStringNotContainsString('name="schedule_on_tue" value="1" checked', $page->body);
        self::assertStringContainsString('name="schedule_time" type="time" value="02:15"', $page->body);
        self::assertStringNotContainsString('Leave as it is', $page->body, 'for one profile there is nothing to leave');
        self::assertStringContainsString('name="p_shop" value="1"', $page->body);
    }

    public function testASavedScheduleIsStoredReportedWithItsNextRunAndWrittenToTheAuditLog(): void
    {
        $response = $this->browser->post('/schedule/edit', ['p_shop' => '1', 'action' => 'save', 'schedule_every' => 'month', 'schedule_day' => '15', 'schedule_time' => '04:45', 'sort' => 'name', 'dir' => 'desc']);

        self::assertSame(303, $response->status);
        self::assertStringContainsString('sort=name', (string) $response->header('Location'), 'back to the view that it was');
        self::assertSame(['every' => 'month', 'day' => 15, 'at' => '04:45'], $this->schedule('shop'));
        $page = $this->flashes();
        self::assertStringContainsString('Shop: on the 15th of every month at 04:45 (next run ', $page);
        $event = (new AuditLog($this->fixture->data . '/audit.log'))->recent(1)[0];
        self::assertSame('schedule.changed', $event['event']);
        self::assertSame('shop', $event['profile'] ?? null);
        self::assertSame('weekly on mon,thu at 02:15', $event['before'] ?? null);
        self::assertSame('monthly on 15 at 04:45', $event['after'] ?? null);
    }

    public function testAMistakeComesBackWithTheFormAndNothingIsStored(): void
    {
        $response = $this->browser->post('/schedule/edit', ['p_blog' => '1', 'action' => 'save', 'schedule_every' => 'day', 'schedule_time' => '25:99']);

        self::assertSame(400, $response->status);
        self::assertStringContainsString('The time of day is a time such as 03:30', $response->body);
        self::assertStringContainsString('name="schedule_time" type="time" value="25:99"', $response->body, 'what was typed is still there');
        self::assertSame(['every' => 'day', 'at' => '03:30'], $this->schedule('blog'));

        $none = $this->browser->post('/schedule/edit', ['p_blog' => '1', 'action' => 'save', 'schedule_every' => 'week', 'schedule_time' => '03:00']);
        self::assertSame(400, $none->status);
        self::assertStringContainsString('Choose at least one day of the week', $none->body);
    }

    public function testARequestWithoutTheFieldsOnlyShowsTheFormAndNeverTakesASchedule(): void
    {
        $response = $this->browser->post('/schedule/edit', ['p_blog' => '1', 'action' => 'save']);

        self::assertSame(200, $response->status);
        self::assertSame(['every' => 'day', 'at' => '03:30'], $this->schedule('blog'));
    }

    public function testNotScheduledTakesTheScheduleAway(): void
    {
        $this->browser->post('/schedule/edit', ['p_blog' => '1', 'action' => 'save', 'schedule_every' => '']);

        self::assertNull($this->schedule('blog'));
        self::assertStringContainsString('My Blog: no schedule any more', $this->flashes());
    }

    public function testThePreviewShowsTheNextRunsAndChangesNothing(): void
    {
        $page = $this->browser->post('/schedule/edit', ['p_blog' => '1', 'action' => 'preview', 'schedule_every' => 'week', 'schedule_on_mon' => '1', 'schedule_time' => '05:00']);

        self::assertSame(200, $page->status);
        self::assertStringContainsString('<strong>Every Monday at 05:00</strong>. The next runs would be:', $page->body);
        self::assertSame(3, substr_count($page->body, '<li>Monday '), 'three dates, all of them Mondays');
        self::assertSame(['every' => 'day', 'at' => '03:30'], $this->schedule('blog'));
    }

    public function testSeveralProfilesAreChangedTogetherAndLeaveItAsItIsChangesNothing(): void
    {
        $form = $this->browser->post('/schedule/action', ['do' => 'edit', 'p_blog' => '1', 'p_shop' => '1']);

        self::assertSame(200, $form->status);
        self::assertStringContainsString('The schedule of these profiles is changed together: ', $form->body);
        self::assertStringContainsString('<option value="keep" selected>Leave as it is</option>', $form->body);

        $kept = $this->browser->post('/schedule/edit', ['p_blog' => '1', 'p_shop' => '1', 'action' => 'save', 'schedule_every' => 'keep']);
        self::assertSame(303, $kept->status);
        self::assertSame(['every' => 'day', 'at' => '03:30'], $this->schedule('blog'));
        self::assertStringContainsString('Nothing was changed', $this->flashes());

        $this->browser->post('/schedule/edit', ['p_blog' => '1', 'p_shop' => '1', 'p_wiki' => '1', 'action' => 'save', 'schedule_every' => 'hours', 'schedule_interval' => '6', 'schedule_minute' => '15']);
        foreach (['blog', 'shop', 'wiki'] as $id) {
            self::assertSame(['every' => 'hours', 'interval' => 6, 'minute' => 15], $this->schedule($id), $id);
        }
        self::assertSame('My Blog', $this->fixture->profiles->load('blog')->name, 'the rest of a profile stays');
    }

    public function testProfilesArePausedAndResumedOneByOneAndTogether(): void
    {
        $this->browser->post('/schedule/action', ['do' => 'pause:blog']);
        self::assertSame(['every' => 'day', 'at' => '03:30', 'paused' => true], $this->schedule('blog'));
        $page = $this->flashes();
        self::assertStringContainsString('My Blog: every day at 03:30, paused.', $page);
        self::assertSame('schedule.paused', (new AuditLog($this->fixture->data . '/audit.log'))->recent(1)[0]['event']);
        self::assertStringContainsString('<span class="badge paused">Paused</span>', $this->browser->get('/schedule')->body);
        self::assertStringContainsString('value="resume:blog"', $this->browser->get('/schedule')->body, 'a paused line offers Resume');

        $this->browser->post('/schedule/action', ['do' => 'pause', 'p_shop' => '1', 'p_wiki' => '1']);
        self::assertTrue($this->schedule('shop')['paused'] ?? false);
        self::assertNull($this->schedule('wiki'), 'a profile without a schedule has nothing to pause');
        self::assertStringContainsString('Not changed: Wiki has no schedule', $this->flashes());

        $this->browser->post('/schedule/action', ['do' => 'resume', 'p_blog' => '1', 'p_shop' => '1']);
        self::assertSame(['every' => 'day', 'at' => '03:30'], $this->schedule('blog'));
        self::assertArrayNotHasKey('paused', $this->schedule('shop') ?? []);
        self::assertSame('schedule.resumed', (new AuditLog($this->fixture->data . '/audit.log'))->recent(1)[0]['event']);
    }

    public function testAnActionWithNothingTickedSaysSo(): void
    {
        $response = $this->browser->post('/schedule/action', ['do' => 'pause']);

        self::assertSame(303, $response->status);
        self::assertStringContainsString('Tick at least one profile first.', $this->flashes());
        self::assertSame(['every' => 'day', 'at' => '03:30'], $this->schedule('blog'));
    }

    public function testWrongRequestsAreRefused(): void
    {
        self::assertSame(404, $this->browser->get('/schedule/edit?profile=nope')->status);
        self::assertSame(404, $this->browser->get('/schedule/edit')->status);
        self::assertSame(403, $this->browser->send('POST', '/schedule/action', ['do' => 'pause', 'p_blog' => '1'])->status, 'without the token of the form');
        self::assertSame(403, $this->browser->send('POST', '/schedule/edit', ['p_blog' => '1', 'action' => 'save', 'schedule_every' => ''])->status);
        self::assertSame(405, $this->browser->get('/schedule/action')->status);
        self::assertSame(['every' => 'day', 'at' => '03:30'], $this->schedule('blog'));
    }

    public function testTheWarningAboutTheTriggerIsOnThePageWhileASchedulesNeedsIt(): void
    {
        self::assertStringContainsString('No trigger has called yet', $this->browser->get('/schedule')->body);

        $this->browser->post('/schedule/edit', ['p_blog' => '1', 'p_shop' => '1', 'action' => 'save', 'schedule_every' => '']);
        self::assertStringNotContainsString('No trigger has called yet', $this->browser->get('/schedule')->body, 'nothing is scheduled any more');
    }
}
