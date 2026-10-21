<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Admin\AuditLog;
use ThreadSafe\Admin\Config;
use ThreadSafe\Backup\BackupName;
use ThreadSafe\Storage\BackupCatalog;
use ThreadSafe\Tests\Support\AdminBrowser;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\Response;

/**
 * Downloading a backup through the pages: the dialog and its warning, the token, the file, the ranges, and what must not work.
 */
final class DownloadPagesTest extends TestCase
{
    use TempDirectory;

    private BackupFixture $fixture;

    private AdminBrowser $browser;

    private BackupName $name;

    /** @var list<string> the paths of the parts */
    private array $parts;

    protected function setUp(): void
    {
        $this->fixture = new BackupFixture($this->tempDirectory());
        $this->browser = new AdminBrowser($this->fixture->data);
        $this->browser->now = time();
        $this->browser->install();
        // a long idle time, so that only the token can run out in the test of its lifetime
        Config::defaults()->with('web.enabled', true)->with('web.sessionIdleMinutes', 1440)->save($this->fixture->data . '/config.json');
        $this->browser->login();
        $this->browser->get('/');
        $this->name = $this->fixture->backup(['partSize' => 1048576]);
        $this->parts = (new BackupCatalog($this->fixture->storage()))->archiveParts($this->name);
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function route(): string
    {
        return '/profiles/blog/backups/' . $this->name->base() . '/download';
    }

    private function body(Response $response): string
    {
        if ($response->stream === null) {
            return $response->body;
        }
        ob_start();
        try {
            ($response->stream)();
        } finally {
            $out = (string) ob_get_clean();
        }

        return $out;
    }

    /** The address of the file for a part, as the page sends the browser to it. */
    private function address(string $file): string
    {
        $issue = $this->browser->post($this->route(), ['part' => $file]);
        self::assertSame(303, $issue->status, $issue->body);
        $location = (string) $issue->header('Location');
        self::assertMatchesRegularExpression('~^/download/[0-9a-f]{48}/' . preg_quote($file, '~') . '$~', $location);

        return $location;
    }

    public function testTheDialogListsEveryPartWithItsSizeAndChecksum(): void
    {
        $page = $this->browser->get($this->route());

        self::assertSame(200, $page->status);
        self::assertStringContainsString('Download every part and keep them together', $page->body);
        foreach ($this->parts as $part) {
            self::assertStringContainsString('<code>' . basename($part) . '</code>', $page->body);
            self::assertStringContainsString((string) hash_file('sha256', $part), $page->body);
        }
        self::assertStringContainsString('sha256sum', $page->body);
        self::assertStringNotContainsString('Large files', $page->body, 'a small backup gets no warning');
    }

    public function testAboveTheThresholdTheDialogWarnsAndNamesThePathACommandAndTheChecksum(): void
    {
        // the smallest threshold is 1 MiB; a backup with 2.5 MB of data that does not compress is above it
        file_put_contents($this->fixture->site . '/assets/huge.bin', random_bytes(2_500_000));
        sleep(1);
        $big = $this->fixture->backup();
        $part = (new BackupCatalog($this->fixture->storage()))->archiveParts($big)[0];
        self::assertGreaterThan(1048576, filesize($part));
        Config::defaults()->with('web.enabled', true)->with('web.sessionIdleMinutes', 1440)->with('download.warnAbove', 1048576)->save($this->fixture->data . '/config.json');

        $page = $this->browser->get('/profiles/blog/backups/' . $big->base() . '/download');

        self::assertStringContainsString('Large files', $page->body);
        self::assertStringContainsString($this->fixture->storageDirectory, $page->body, 'the absolute path on the server');
        self::assertStringContainsString('scp USER@HOST:' . $this->fixture->storageDirectory . '/' . basename($part), $page->body);
        self::assertStringContainsString('rsync -avP USER@HOST:', $page->body);
        self::assertStringContainsString((string) hash_file('sha256', $part), $page->body, 'the checksum to compare with');
        self::assertStringContainsString('<button type="submit">Download</button>', $page->body, 'the button is still there');
    }

    public function testTheTokenLeadsToTheFileAndTheBytesAreTheFilesBytes(): void
    {
        $file = basename($this->parts[0]);
        $address = $this->address($file);

        $response = $this->browser->get($address);

        self::assertSame(200, $response->status);
        self::assertSame((string) filesize($this->parts[0]), $response->header('Content-Length'));
        self::assertSame('bytes', $response->header('Accept-Ranges'));
        self::assertSame('"' . hash_file('sha256', $this->parts[0]) . '"', $response->header('ETag'));
        self::assertStringContainsString('attachment; filename="' . $file . '"', (string) $response->header('Content-Disposition'));
        self::assertSame('private, no-transform', $response->header('Cache-Control'), 'a download may be resumed, so it is not "no-store"');
        self::assertStringContainsString("frame-ancestors 'none'", (string) $response->header('Content-Security-Policy'));
        self::assertSame(hash_file('sha256', $this->parts[0]), hash('sha256', $this->body($response)));
    }

    public function testABrokenDownloadIsResumedWithTheSameAddress(): void
    {
        $file = basename($this->parts[0]);
        $address = $this->address($file);
        $content = (string) file_get_contents($this->parts[0]);
        $got = substr($content, 0, 300_000);   // the connection broke here

        $rest = $this->browser->send('GET', $address, [], ['range' => 'bytes=300000-', 'if-range' => '"' . hash_file('sha256', $this->parts[0]) . '"']);

        self::assertSame(206, $rest->status);
        self::assertSame($content, $got . $this->body($rest));
        // and once more, the address still works
        self::assertSame(206, $this->browser->send('GET', $address, [], ['range' => 'bytes=0-99'])->status);
    }

    public function testEveryPartHasItsOwnToken(): void
    {
        self::assertGreaterThan(1, count($this->parts));
        $a = $this->address(basename($this->parts[0]));
        $b = $this->address(basename($this->parts[1]));

        self::assertNotSame(explode('/', $a)[2], explode('/', $b)[2]);
        self::assertSame(hash_file('sha256', $this->parts[1]), hash('sha256', $this->body($this->browser->get($b))));
        // the token of one part does not give another
        $crossed = '/download/' . explode('/', $a)[2] . '/' . basename($this->parts[1]);
        self::assertSame(404, $this->browser->get($crossed)->status);
    }

    public function testATokenOfAnotherSessionOrAfterLogoutOrAfterTwoHoursIsWorthNothing(): void
    {
        $file = basename($this->parts[0]);
        $address = $this->address($file);

        $other = new AdminBrowser($this->fixture->data);
        $other->now = $this->browser->now;
        $other->login();
        self::assertSame(404, $other->get($address)->status, 'another session');

        $this->browser->now += 7300;
        self::assertSame(404, $this->browser->get($address)->status, 'two hours are over');

        $this->browser->now -= 7000;
        $fresh = $this->address($file);
        $this->browser->post('/logout');
        $this->browser->login();
        self::assertSame(404, $this->browser->get($fresh)->status, 'the logout took the token with it');
    }

    public function testWithoutALoginNothingIsServedEvenWithAValidAddress(): void
    {
        $address = $this->address(basename($this->parts[0]));
        $stranger = new AdminBrowser($this->fixture->data);
        $stranger->now = $this->browser->now;

        $response = $stranger->get($address);

        self::assertSame(303, $response->status);
        self::assertSame('/login', $response->header('Location'));
        self::assertNull($response->stream);
    }

    public function testNoNameOutsideTheRecordOfTheBackupCanBeAskedFor(): void
    {
        foreach (['../../data/admin.json', 'admin.json', '.htaccess', 'index.php', basename($this->parts[0]) . '.bak'] as $file) {
            self::assertSame(404, $this->browser->post($this->route(), ['part' => $file])->status, $file);
        }
        $address = $this->address(basename($this->parts[0]));
        $token = explode('/', $address)[2];
        foreach (['..', '%2e%2e', '.htaccess', 'index.php'] as $file) {
            self::assertSame(404, $this->browser->get("/download/$token/$file")->status, $file);
        }
        self::assertSame(404, $this->browser->get('/download/' . str_repeat('0', 48) . '/' . basename($this->parts[0]))->status);
    }

    public function testTheStartAndTheEndAreInTheAuditLogWithoutTheToken(): void
    {
        $file = basename($this->parts[0]);
        $address = $this->address($file);
        $this->body($this->browser->get($address));

        $recent = (new AuditLog($this->fixture->data . '/audit.log'))->recent(2);

        self::assertSame(['download.end', 'download.start'], array_column($recent, 'event'));
        self::assertTrue($recent[0]['complete']);
        self::assertSame(filesize($this->parts[0]), $recent[0]['sent']);
        self::assertSame($file, $recent[0]['file']);
        self::assertStringNotContainsString(explode('/', $address)[2], (string) file_get_contents($this->fixture->data . '/audit.log'));
    }

    public function testTheLinksAreOnTheProfilePageAndTheDetails(): void
    {
        self::assertStringContainsString($this->route() . '"', $this->browser->get('/profiles/blog')->body);
        self::assertStringContainsString($this->route() . '"', $this->browser->get('/profiles/blog/backups/' . $this->name->base())->body);
    }

    public function testAnUnknownBackupHasNoDialog(): void
    {
        self::assertSame(404, $this->browser->get('/profiles/blog/backups/blog_20261021-120000_abcd1234/download')->status);
        self::assertSame(404, $this->browser->post('/profiles/blog/backups/blog_20261021-120000_abcd1234/download', ['part' => 'x.zip'])->status);
        self::assertSame(403, $this->browser->send('POST', $this->route(), ['part' => basename($this->parts[0])])->status, 'a POST needs the token of the session');
    }
}
