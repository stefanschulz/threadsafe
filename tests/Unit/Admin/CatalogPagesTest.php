<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Admin\AuditLog;
use ThreadSafe\Backup\BackupName;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Storage\BackupCatalog;
use ThreadSafe\Tests\Support\AdminBrowser;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\Response;

/**
 * The backups on the profile page, one backup in detail, checking it and deleting it.
 */
final class CatalogPagesTest extends TestCase
{
    use TempDirectory;

    private BackupFixture $fixture;

    private AdminBrowser $browser;

    protected function setUp(): void
    {
        $this->fixture = new BackupFixture($this->tempDirectory());
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

    /**
     * @param array<string, mixed> $parameters
     */
    private function backup(array $parameters = []): BackupName
    {
        return $this->fixture->backup($parameters);
    }

    private function checkId(Response $start): string
    {
        self::assertSame(303, $start->status, $start->body);
        self::assertMatchesRegularExpression('~^/checks/(verify-\d{8}-\d{6}-[0-9a-f]{8})$~', (string) $start->header('Location'));

        return substr((string) $start->header('Location'), strlen('/checks/'));
    }

    /**
     * @return array<string, mixed>
     */
    private function tickToTheEnd(string $id): array
    {
        $last = [];
        for ($i = 0; $i < 500; ++$i) {
            $response = $this->browser->send('POST', "/checks/$id/tick", [], ['x-csrf-token' => $this->browser->csrf]);
            $last = json_decode($response->body, true);
            self::assertIsArray($last, $response->body);
            if ($last['status'] !== 'running') {
                return $last;
            }
        }
        self::fail('the check did not end');
    }

    public function testTheProfilePageListsTheBackupsWithTheirStateAndActions(): void
    {
        self::assertStringContainsString('There are no backups of this site yet.', $this->browser->get('/profiles/blog')->body);
        $name = $this->backup(['partSize' => 1048576]);

        $page = $this->browser->get('/profiles/blog');

        self::assertStringContainsString('<h2>Backups</h2>', $page->body);
        self::assertStringContainsString('href="/profiles/blog/backups/' . $name->base() . '"', $page->body);
        self::assertStringContainsString('<time datetime="' . $name->time->format('Y-m-d\TH:i:s\Z') . '">', $page->body);
        self::assertMatchesRegularExpression('/\d+(\.\d)? MB in \d+ parts/', $page->body);
        self::assertStringContainsString('<strong>ok</strong>', $page->body);
        self::assertStringContainsString('/profiles/blog/backups/' . $name->base() . '/verify', $page->body);
        self::assertStringContainsString('/profiles/blog/backups/' . $name->base() . '/delete', $page->body);
    }

    public function testTheDetailsShowTheManifestAndTheParts(): void
    {
        $name = $this->backup(['partSize' => 1048576]);

        $page = $this->browser->get('/profiles/blog/backups/' . $name->base());

        self::assertSame(200, $page->status);
        self::assertStringContainsString('Backup ' . $name->base(), $page->body);
        self::assertStringContainsString('website type generic', $page->body);
        self::assertStringContainsString('4 files', $page->body);
        self::assertStringContainsString('<h2>Parts</h2>', $page->body);
        self::assertStringContainsString($name->base() . '-p01.zip', $page->body);
        self::assertMatchesRegularExpression('/<code class="hash" id="hash-\d+" title="([0-9a-f]{64})">\1<\/code> <button type="button" class="secondary small" data-copy="#hash-\d+">Copy<\/button>/', $page->body, 'the SHA-256 of a part: whole in the tooltip and in what is copied, cut by the style sheet');
        self::assertStringContainsString('<table class="parts">', $page->body);
        self::assertStringContainsString('<code>sha256sum FILE</code> must give the same value', $page->body, 'what the hash is for');
        self::assertStringContainsString('Check that it is intact', $page->body);
    }

    public function testWhatWasLeftOutIsAListAndNotARunOfCode(): void
    {
        $this->fixture->profiles->save($this->fixture->profiles->load('blog')->with(['excludePaths' => ['var/cache', '*.log'], 'structureOnlyTables' => ['ps_log', 'ps_connections']]));
        $name = $this->backup();

        $page = $this->browser->get('/profiles/blog/backups/' . $name->base());

        self::assertStringContainsString('<h2>Left out of the files</h2><ul class="listing"><li><code>var/cache</code></li><li><code>*.log</code></li></ul>', $page->body);
        self::assertStringContainsString('<h2>Tables kept without their rows</h2><ul class="listing"><li><code>ps_log</code></li><li><code>ps_connections</code></li></ul>', $page->body);
    }

    public function testCheckingAnIntactBackupGoesInTicksAndEndsWithAnOk(): void
    {
        $name = $this->backup(['partSize' => 1048576]);
        $id = $this->checkId($this->browser->post('/profiles/blog/backups/' . $name->base() . '/verify'));

        $page = $this->browser->get("/checks/$id");
        self::assertStringContainsString('Check running', $page->body);
        self::assertStringContainsString('data-tick-url="/checks/' . $id . '/tick"', $page->body);

        $end = $this->tickToTheEnd($id);

        self::assertSame('finished', $end['status']);
        $done = $this->browser->get("/checks/$id");
        self::assertStringContainsString('Check finished: intact', $done->body);
        self::assertStringContainsString('is intact (full check: ', $done->body);
        self::assertSame('backup.checked', (new AuditLog($this->fixture->data . '/audit.log'))->recent(1)[0]['event']);
        self::assertTrue((new AuditLog($this->fixture->data . '/audit.log'))->recent(1)[0]['intact']);
    }

    public function testACheckOfADamagedBackupNamesTheProblemAndTheListSaysFaulty(): void
    {
        $name = $this->backup();
        $this->fixture->damage((new BackupCatalog($this->fixture->storage()))->archiveParts($name)[0]);
        $id = $this->checkId($this->browser->post('/profiles/blog/backups/' . $name->base() . '/verify'));

        $this->tickToTheEnd($id);

        $done = $this->browser->get("/checks/$id");
        self::assertStringContainsString('Check finished: NOT intact', $done->body);
        self::assertStringContainsString('is NOT intact', $done->body);
        self::assertStringContainsString('SHA-256', $done->body);
        self::assertStringContainsString('<strong>FAULTY</strong>', $this->browser->get('/profiles/blog')->body);
    }

    public function testAnotherCheckCannotStartWhileOneIsGoing(): void
    {
        $name = $this->backup();
        $id = $this->checkId($this->browser->post('/profiles/blog/backups/' . $name->base() . '/verify'));

        $second = $this->browser->post('/profiles/blog/backups/' . $name->base() . '/verify');

        self::assertSame(303, $second->status);
        self::assertSame('/profiles/blog', $second->header('Location'));
        $profile = $this->browser->get('/profiles/blog')->body;
        self::assertStringContainsString('One check at a time', $profile);
        self::assertStringContainsString('href="/checks/' . $id . '"', $profile, 'the check that is going is linked');
    }

    public function testDeletingAsksFirstNeedsThePostAndTheTokenAndRemovesTheFiles(): void
    {
        $name = $this->backup();
        $other = $this->backup();
        $route = '/profiles/blog/backups/' . $name->base();

        $ask = $this->browser->get($route . '/delete');
        self::assertSame(200, $ask->status);
        self::assertStringContainsString('It cannot be undone', $ask->body);
        self::assertCount(2, $this->fixture->storage()->names('blog'), 'asking deletes nothing');

        self::assertSame(403, $this->browser->send('POST', $route . '/delete')->status);
        self::assertCount(2, $this->fixture->storage()->names('blog'));

        $done = $this->browser->post($route . '/delete');
        self::assertSame(303, $done->status);
        self::assertSame('/profiles/blog', $done->header('Location'));
        self::assertSame([$other->base()], array_map(static fn (BackupName $n): string => $n->base(), $this->fixture->storage()->names('blog')));
        self::assertStringContainsString('was deleted', $this->browser->get('/profiles/blog')->body);
        self::assertSame('backup.deleted', (new AuditLog($this->fixture->data . '/audit.log'))->recent(1)[0]['event']);
        self::assertSame(404, $this->browser->get($route)->status);
    }

    public function testAProfileCanBeRemovedTogetherWithItsBackups(): void
    {
        $this->backup();
        $this->backup();

        $ask = $this->browser->get('/profiles/blog/delete');
        self::assertStringContainsString('Also delete its 2 backups', $ask->body);

        $without = $this->browser->post('/profiles/blog/delete', ['delete_backups' => '0']);
        self::assertSame(303, $without->status);
        self::assertCount(2, $this->fixture->storage()->names('blog'), 'without the tick the backups stay');
        self::assertFalse($this->fixture->profiles->exists('blog'));
    }

    public function testRemovingWithTheTickDeletesTheBackupsToo(): void
    {
        $this->backup();
        $this->backup();

        $this->browser->post('/profiles/blog/delete', ['delete_backups' => '1']);

        self::assertSame([], $this->fixture->storage()->names('blog'));
        self::assertFalse($this->fixture->profiles->exists('blog'));
        $audit = (new AuditLog($this->fixture->data . '/audit.log'))->recent(1)[0];
        self::assertSame('profile.removed', $audit['event']);
        self::assertSame(2, $audit['backupsDeleted']);
    }

    public function testUnknownAndMalformedBackupsAndChecksAreNotFound(): void
    {
        foreach (['/profiles/blog/backups/blog_20261021-120000_abcd1234', '/profiles/blog/backups/nonsense', '/profiles/blog/backups/blog_20261021-120000_abcd1234/delete', '/checks/verify-20261021-120000-00000000', '/checks/..'] as $route) {
            self::assertSame(404, $this->browser->get($route)->status, $route);
        }
        self::assertSame(404, $this->browser->post('/profiles/blog/backups/blog_20261021-120000_abcd1234/verify')->status);
        $tick = $this->browser->send('POST', '/checks/verify-20261021-120000-00000000/tick', [], ['x-csrf-token' => $this->browser->csrf]);
        self::assertSame(404, $tick->status);
        self::assertSame(405, $this->browser->get('/profiles/blog/backups/blog_20261021-120000_abcd1234/verify')->status);
    }

    public function testABackupOfAnotherProfileIsNotReachableThroughThisOne(): void
    {
        $name = $this->backup();

        self::assertSame(404, $this->browser->get('/profiles/other/backups/' . $name->base())->status);
        $this->fixture->profiles->save(\ThreadSafe\Profile\SiteProfile::fromArray(['id' => 'other', 'type' => 'generic', 'path' => $this->fixture->site, 'storage' => $this->fixture->storageDirectory]));
        self::assertSame(404, $this->browser->get('/profiles/other/backups/' . $name->base())->status, 'the name says it belongs to "blog"');
        self::assertSame(404, $this->browser->post('/profiles/other/backups/' . $name->base() . '/delete')->status);
        self::assertCount(1, $this->fixture->storage()->names('blog'));
    }
}
