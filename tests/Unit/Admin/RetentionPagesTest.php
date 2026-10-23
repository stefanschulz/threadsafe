<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use ThreadSafe\App\RetentionService;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Retention\RetentionStateStore;
use ThreadSafe\Tests\Support\AdminBrowser;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\FakeBackups;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * Retention and pinning in the browser: the preview on the profile page, applying the rules, the pin on the page of a backup, and the
 * schedule and retention fields of the profile form.
 */
final class RetentionPagesTest extends TestCase
{
    use TempDirectory;

    private BackupFixture $fixture;

    private FakeBackups $fake;

    private AdminBrowser $browser;

    protected function setUp(): void
    {
        $this->fixture = new BackupFixture($this->tempDirectory());
        $this->fake = new FakeBackups($this->fixture);
        $this->browser = new AdminBrowser($this->fixture->data);
        $this->browser->now = time();
        $this->browser->install();
        $this->browser->login();
        $this->browser->get('/');
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function ago(int $days): string
    {
        return gmdate('Y-m-d H:i:s', $this->browser->now - $days * 86400);
    }

    /** @param array<string, int> $rules */
    private function rules(array $rules): void
    {
        $this->fixture->profiles->save($this->fixture->profiles->load('blog')->with(['retention' => $rules]));
    }

    public function testWithoutRulesThePageSaysSo(): void
    {
        $page = $this->browser->get('/profiles/blog?tab=retention');

        self::assertSame(200, $page->status);
        self::assertStringContainsString('has no retention rules', $page->body);
        self::assertStringNotContainsString('Apply the rules now', $page->body);
    }

    public function testThePageShowsWhatTheRulesWouldDeleteAndAppliesThemOnRequest(): void
    {
        $names = [];
        foreach ([1, 2, 3, 4] as $days) {
            $names[$days] = $this->fake->make($this->ago($days));
        }
        $this->rules(['keep' => 2]);

        $page = $this->browser->get('/profiles/blog?tab=retention');
        self::assertStringContainsString('Rules: at most 2 backups', $page->body);
        self::assertStringContainsString('these would be deleted, the oldest first', $page->body);
        self::assertStringContainsString($names[4]->base(), $page->body);
        self::assertStringContainsString('keep 2: there are 4 backups that count', $page->body);
        self::assertStringContainsString('Apply the rules now', $page->body);
        self::assertTrue($this->fake->exists($names[4]), 'showing deletes nothing');

        $applied = $this->browser->post('/profiles/blog/retention');
        self::assertSame(303, $applied->status);
        self::assertFalse($this->fake->exists($names[4]));
        self::assertFalse($this->fake->exists($names[3]));
        self::assertTrue($this->fake->exists($names[2]) && $this->fake->exists($names[1]));
        $after = $this->browser->get('/profiles/blog?tab=retention');
        self::assertStringContainsString('2 backups deleted', $after->body);
        self::assertStringContainsString('nothing would be deleted', $after->body);
        self::assertStringContainsString('Retention last ran', $after->body);
        $audit = (string) file_get_contents($this->fixture->data . '/audit.log');
        self::assertSame(2, substr_count($audit, '"event":"retention.deleted"'));
        self::assertStringContainsString('"user":"admin"', $audit);
    }

    public function testRulesThatWaitForTheGoAheadAreSaidSo(): void
    {
        foreach ([1, 2, 3, 4, 5] as $days) {
            $this->fake->make($this->ago($days));
        }
        $this->rules(['keep' => 1]);
        $service = RetentionService::create($this->fixture->data, fn (): int => $this->browser->now);
        self::assertNull($service->automatic($this->fixture->profiles->load('blog')));

        $page = $this->browser->get('/profiles/blog?tab=retention');

        self::assertStringContainsString('would delete 4 backups. The trigger waits for your go-ahead', $page->body);
        self::assertSame(4, (new RetentionStateStore($this->fixture->data . '/retention'))->load('blog')->waiting);
        $this->browser->post('/profiles/blog/retention');
        self::assertStringNotContainsString('waits for your go-ahead', $this->browser->get('/profiles/blog?tab=retention')->body);
    }

    public function testApplyingNeedsAPostTheTokenAndRules(): void
    {
        $this->fake->make($this->ago(1));

        self::assertSame(405, $this->browser->get('/profiles/blog/retention')->status);
        self::assertSame(403, $this->browser->send('POST', '/profiles/blog/retention')->status);
        $this->browser->post('/profiles/blog/retention');
        self::assertStringContainsString('has no retention rules', $this->browser->get('/profiles/blog?tab=retention')->body);
        self::assertSame(404, $this->browser->post('/profiles/nobody/retention')->status);
    }

    public function testABackupIsPinnedAndUnpinnedOnItsPage(): void
    {
        $name = $this->fake->make($this->ago(5));
        $base = $name->base();

        $page = $this->browser->get("/profiles/blog/backups/$base");
        self::assertStringContainsString('Pin it', $page->body);
        self::assertStringContainsString('by the retention rules of the profile', $page->body);

        self::assertSame(303, $this->browser->post("/profiles/blog/backups/$base/pin", ['pinned' => '1'])->status);
        self::assertTrue($this->fixture->storage()->readSidecar($name)->pinned);
        $pinned = $this->browser->get("/profiles/blog/backups/$base");
        self::assertStringContainsString('Unpin', $pinned->body);
        self::assertStringContainsString('pinned: retention never deletes it', $pinned->body);
        self::assertStringContainsString('(pinned)', $this->browser->get('/profiles/blog')->body, 'the table shows it');

        $this->browser->post("/profiles/blog/backups/$base/pin", ['pinned' => '0']);
        self::assertFalse($this->fixture->storage()->readSidecar($name)->pinned);
        $audit = (string) file_get_contents($this->fixture->data . '/audit.log');
        self::assertStringContainsString('backup.pinned', $audit);
        self::assertStringContainsString('backup.unpinned', $audit);
    }

    public function testAPinnedBackupSurvivesTheRulesThatWouldDeleteIt(): void
    {
        $this->fake->make($this->ago(1));
        $old = $this->fake->make($this->ago(100));
        $this->browser->post('/profiles/blog/backups/' . $old->base() . '/pin', ['pinned' => '1']);
        $this->rules(['maxAgeDays' => 30]);

        $page = $this->browser->get('/profiles/blog?tab=retention');

        self::assertStringContainsString('nothing would be deleted', $page->body);
        self::assertTrue($this->fake->exists($old));
    }

    public function testPinningNeedsAPostAndTheToken(): void
    {
        $name = $this->fake->make($this->ago(2));
        $route = '/profiles/blog/backups/' . $name->base() . '/pin';

        self::assertSame(405, $this->browser->get($route)->status);
        self::assertSame(403, $this->browser->send('POST', $route, ['pinned' => '1'])->status);
        self::assertFalse($this->fixture->storage()->readSidecar($name)->pinned);
        self::assertSame(404, $this->browser->post('/profiles/blog/backups/blog_20260101-000000_deadbeef/pin', ['pinned' => '1'])->status);
    }

    // ------------------------------------------------------------------------------------ the form

    /**
     * @param array<string, string> $changes
     *
     * @return array<string, string>
     */
    private function fields(array $changes = []): array
    {
        return $changes + ['action' => 'save', 'skip_test' => '1', 'id' => 'news', 'name' => 'News', 'type' => 'generic', 'path' => $this->fixture->site, 'storage' => $this->fixture->storageDirectory . '-news',
            'compression' => '6', 'part_size' => '', 'db_mode' => 'site', 'default_filters' => '1', 'exclude' => '', 'structure_only' => '', 'note' => ''];
    }

    public function testTheFormTakesAScheduleAndRetentionRulesAndShowsThemAgain(): void
    {
        $response = $this->browser->post('/profiles', $this->fields(['schedule' => ' 30 3 * * * ', 'keep' => '14', 'max_age_days' => '90', 'max_size' => '20G']));

        self::assertSame(303, $response->status, $response->body);
        $profile = (new ProfileStore($this->fixture->data . '/profiles'))->load('news');
        self::assertSame('30 3 * * *', $profile->schedule?->cron);
        self::assertSame([14, 90, 21474836480], [$profile->retention?->keep, $profile->retention?->maxAgeDays, $profile->retention?->maxBytes]);

        $edit = $this->browser->get('/profiles/news/edit');
        self::assertStringContainsString('name="schedule" type="text" value="30 3 * * *"', $edit->body);
        self::assertStringContainsString('name="keep" type="text" value="14"', $edit->body);
        self::assertStringContainsString('name="max_size" type="text" value="20G"', $edit->body);
        self::assertStringContainsString('Read in the time zone ' . date_default_timezone_get(), $edit->body);
    }

    public function testEmptyFieldsTakeTheRulesAwayAndMistakesComeBackWithTheForm(): void
    {
        $this->browser->post('/profiles', $this->fields(['schedule' => '@daily', 'keep' => '5']));
        $store = new ProfileStore($this->fixture->data . '/profiles');
        self::assertNotNull($store->load('news')->schedule);

        $this->browser->post('/profiles/news', $this->fields(['schedule' => '', 'keep' => '', 'max_age_days' => '', 'max_size' => '']));
        self::assertNull($store->load('news')->schedule);
        self::assertNull($store->load('news')->retention);

        $wrong = $this->browser->post('/profiles/news', $this->fields(['schedule' => '61 3 * * *']));
        self::assertSame(400, $wrong->status);
        self::assertStringContainsString('out of range', $wrong->body);
        self::assertNull($store->load('news')->schedule, 'nothing was stored');

        $wrong = $this->browser->post('/profiles/news', $this->fields(['keep' => 'ten', 'max_size' => 'lots']));
        self::assertSame(400, $wrong->status);
        self::assertStringContainsString('Keep is a whole number', $wrong->body);
        self::assertStringContainsString('The size limit is a size', $wrong->body);
    }
}
