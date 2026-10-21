<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Admin\AuditLog;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\Lock;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Tests\Support\AdminBrowser;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\Response;

/**
 * A backup started and followed through the pages: start, the page of the run, the ticks, giving up, and what the other pages show.
 */
final class BackupPagesTest extends TestCase
{
    use TempDirectory;

    private string $base;

    private BackupFixture $fixture;

    private AdminBrowser $browser;

    protected function setUp(): void
    {
        $this->base = $this->tempDirectory();
        $this->fixture = new BackupFixture($this->base . '/fixture');
        // a budget of nothing: every tick does one small step, as under a very short time limit
        $this->browser = new AdminBrowser($this->fixture->data, '', static fn (): Budget => new Budget(0.0));
        $this->browser->now = time();
        $this->browser->install();
        $this->browser->login();
        $this->browser->get('/');
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function runId(Response $start): string
    {
        self::assertSame(303, $start->status);
        self::assertMatchesRegularExpression('~^/runs/(backup-\d{8}-\d{6}-[0-9a-f]{8})$~', (string) $start->header('Location'));

        return substr((string) $start->header('Location'), strlen('/runs/'));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $data = json_decode($response->body, true);
        self::assertIsArray($data, $response->body);
        self::assertSame('application/json; charset=utf-8', $response->header('Content-Type'));

        return $data;
    }

    private function store(): RunStore
    {
        return $this->fixture->runs;
    }

    public function testTheProfilePageOffersToBackUpAndNothingStartsByItself(): void
    {
        $page = $this->browser->get('/profiles/blog');

        self::assertStringContainsString('Back up now', $page->body);
        self::assertStringContainsString('action="/profiles/blog/backup"', $page->body);
        self::assertStringContainsString('too little room', $page->body);
        self::assertSame([], $this->store()->all());
    }

    public function testStartingMakesAnActiveRunAndItsPageAsksForTicksByScript(): void
    {
        $id = $this->runId($this->browser->post('/profiles/blog/backup'));

        $page = $this->browser->get("/runs/$id");

        self::assertSame(200, $page->status);
        self::assertStringContainsString('Backup running', $page->body);
        self::assertStringContainsString('data-tick-url="/runs/' . $id . '/tick"', $page->body);
        self::assertStringContainsString('<progress id="run-progress" max="100">', $page->body, 'no value while the site is listed');
        self::assertStringContainsString("/runs/$id/continue", $page->body, 'the button for a browser without script');
        self::assertStringContainsString("/runs/$id/abandon", $page->body);
        self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/', $page->body, 'the bar is a <progress>, not a styled div');
        self::assertSame('backup.started', (new AuditLog($this->fixture->data . '/audit.log'))->recent(1)[0]['event']);
    }

    public function testTheTicksRunTheBackupToTheEndAndThePageThenShowsTheResult(): void
    {
        $id = $this->runId($this->browser->post('/profiles/blog/backup'));

        $statuses = [];
        for ($i = 0; $i < 300; ++$i) {
            $data = $this->json($this->browser->send('POST', "/runs/$id/tick", [], ['x-csrf-token' => $this->browser->csrf]));
            $statuses[] = $data['status'];
            if ($data['status'] !== 'running') {
                break;
            }
            self::assertIsString($data['label']);
            self::assertArrayHasKey('percent', $data);
        }

        self::assertSame('finished', end($statuses));
        self::assertGreaterThan(3, count($statuses));
        $page = $this->browser->get("/runs/$id");
        self::assertStringContainsString('Backup finished', $page->body);
        self::assertStringContainsString('is complete', $page->body);
        self::assertStringContainsString('blog_', $page->body, 'the name of the archive');
        self::assertCount(1, glob($this->fixture->storageDirectory . '/blog_*.zip') ?: []);
        self::assertSame(['backup.finished', 'backup.started'], array_column((new AuditLog($this->fixture->data . '/audit.log'))->recent(2), 'event'));

        // asking once more changes nothing and does not log a second finish
        $again = $this->json($this->browser->send('POST', "/runs/$id/tick", [], ['x-csrf-token' => $this->browser->csrf]));
        self::assertSame('finished', $again['status']);
        self::assertSame(['backup.finished', 'backup.started'], array_column((new AuditLog($this->fixture->data . '/audit.log'))->recent(2), 'event'));
    }

    public function testTheButtonWithoutScriptDoesOneStepAndComesBack(): void
    {
        $id = $this->runId($this->browser->post('/profiles/blog/backup'));

        $response = $this->browser->post("/runs/$id/continue");

        self::assertSame(303, $response->status);
        self::assertSame("/runs/$id", $response->header('Location'));
        self::assertSame(1, $this->store()->load($id)->ticks);
        self::assertStringContainsString('scanning the site', $this->browser->get("/runs/$id")->body, 'the page tells where the run is');
    }

    public function testASecondBackupIsRefusedAndLeadsToTheRunningOne(): void
    {
        $id = $this->runId($this->browser->post('/profiles/blog/backup'));

        $second = $this->browser->post('/profiles/blog/backup');

        self::assertSame(303, $second->status);
        self::assertSame("/runs/$id", $second->header('Location'));
        self::assertStringContainsString('One backup at a time', $this->browser->get("/runs/$id")->body);
        self::assertCount(1, $this->store()->all());
        $profilePage = $this->browser->get('/profiles/blog')->body;
        self::assertStringContainsString('follow it', $profilePage);
        self::assertStringNotContainsString('Back up now', $profilePage);
    }

    public function testTheRunShowsOnTheProfilePageAndTheDashboardWhileItGoes(): void
    {
        $id = $this->runId($this->browser->post('/profiles/blog/backup'));

        foreach (['/', '/profiles/blog'] as $route) {
            $body = $this->browser->get($route)->body;
            self::assertStringContainsString('href="/runs/' . $id . '"', $body, $route);
            self::assertStringContainsString('running', $body, $route);
        }
    }

    public function testARunThatNobodyTicksAnyMoreIsInterruptedAndCanBeContinuedOrGivenUp(): void
    {
        $id = $this->runId($this->browser->post('/profiles/blog/backup'));
        $this->browser->post("/runs/$id/continue");
        $this->browser->now += 3600; // the tab was closed an hour ago
        $this->browser->send('GET', '/'); // a request carries the clock on; the session may have ended meanwhile
        $this->browser->login();

        $page = $this->browser->get("/runs/$id");
        self::assertStringContainsString('Backup interrupted', $page->body);
        self::assertStringContainsString('Continue the backup', $page->body);
        self::assertStringNotContainsString('data-tick-url', $page->body, 'an interrupted run is not ticked by itself');

        // a new backup is allowed next to an interrupted one
        $other = $this->browser->post('/profiles/blog/backup');
        $otherId = $this->runId($other);
        self::assertNotSame($id, $otherId);
        $this->browser->post("/runs/$otherId/abandon");

        $gone = $this->browser->post("/runs/$id/abandon");
        self::assertSame(303, $gone->status);
        self::assertSame('/profiles/blog', $gone->header('Location'));
        self::assertSame(404, $this->browser->get("/runs/$id")->status);
        self::assertSame([], glob($this->fixture->storageDirectory . '/.work-*') ?: []);
        self::assertSame('backup.abandoned', (new AuditLog($this->fixture->data . '/audit.log'))->recent(1)[0]['event']);
    }

    public function testARunThatAProcessIsWorkingOnCannotBeGivenUpOrTickedTwice(): void
    {
        $id = $this->runId($this->browser->post('/profiles/blog/backup'));
        $lock = Lock::tryAcquire($this->store()->lockPath($id));

        $tick = $this->json($this->browser->send('POST', "/runs/$id/tick", [], ['x-csrf-token' => $this->browser->csrf]));
        $abandon = $this->browser->post("/runs/$id/abandon");
        $lock?->release();

        self::assertSame('busy', $tick['status']);
        self::assertSame(303, $abandon->status);
        self::assertSame("/runs/$id", $abandon->header('Location'));
        $page = $this->browser->get("/runs/$id");
        self::assertSame(200, $page->status);
        self::assertStringContainsString('right now', $page->body, 'the reason is shown');
    }

    public function testABackupThatFailsShowsItsReasonOnceAndCanBeDismissed(): void
    {
        $id = $this->runId($this->browser->post('/profiles/blog/backup'));
        $this->browser->send('POST', "/runs/$id/tick", [], ['x-csrf-token' => $this->browser->csrf]);
        // the site disappears under the run
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->fixture->site, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->fixture->site);

        $failed = null;
        for ($i = 0; $i < 50 && $failed === null; ++$i) {
            $data = $this->json($this->browser->send('POST', "/runs/$id/tick", [], ['x-csrf-token' => $this->browser->csrf]));
            $failed = $data['status'] === 'failed' ? $data : null;
        }

        self::assertNotNull($failed);
        self::assertNotSame('', $failed['error']);
        $page = $this->browser->get("/runs/$id");
        self::assertStringContainsString('Backup failed', $page->body);
        self::assertStringContainsString('is removed', $page->body);
        self::assertSame([], glob($this->fixture->storageDirectory . '/.work-*') ?: []);
        self::assertSame('backup.failed', (new AuditLog($this->fixture->data . '/audit.log'))->recent(1)[0]['event']);
        // a failed run does not stand in the way of the next backup
        self::assertSame(303, $this->browser->post('/profiles/blog/backup')->status);

        $dismiss = $this->browser->post("/runs/$id/dismiss");
        self::assertSame(303, $dismiss->status);
        self::assertSame(404, $this->browser->get("/runs/$id")->status);
    }

    public function testNothingChangesWithoutTheTokenOrOnAGet(): void
    {
        $id = $this->runId($this->browser->post('/profiles/blog/backup'));

        self::assertSame(403, $this->browser->send('POST', '/profiles/blog/backup')->status);
        self::assertSame(403, $this->browser->send('POST', "/runs/$id/continue")->status);
        self::assertSame(403, $this->browser->send('POST', "/runs/$id/abandon")->status);
        self::assertSame(403, $this->browser->send('POST', "/runs/$id/tick")->status);
        self::assertSame(405, $this->browser->get("/runs/$id/tick")->status);
        self::assertSame(405, $this->browser->get("/runs/$id/abandon")->status);
        self::assertSame(405, $this->browser->get('/profiles/blog/backup')->status);
        self::assertSame(0, $this->store()->load($id)->ticks);
    }

    public function testUnknownAndMalformedRunsAreNotFound(): void
    {
        self::assertSame(404, $this->browser->get('/runs/backup-20261021-120000-00000000')->status);
        self::assertSame(404, $this->browser->get('/runs/..')->status);
        self::assertSame(404, $this->browser->get('/runs/Backup-1')->status);
        $tick = $this->browser->send('POST', '/runs/backup-20261021-120000-00000000/tick', [], ['x-csrf-token' => $this->browser->csrf]);
        self::assertSame(404, $tick->status);
        self::assertSame('missing', $this->json($tick)['status']);
        self::assertSame(303, $this->browser->post('/profiles/nope/backup')->status);
        self::assertStringContainsString('There is no profile', $this->browser->get('/profiles')->body . $this->browser->get('/profiles/nope')->body . $this->browser->get('/')->body);
    }
}
