<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use ThreadSafe\Build\RestoreBuilder;
use ThreadSafe\Storage\BackupCatalog;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\FreshHost;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The restore through the browser behind a real Apache (the host with nothing on it, limits of 5 seconds and 64 MB), for a site whose
 * `.htaccess` is the one of a security extension: it refuses every direct request for a PHP file but index.php. Field test (Admin Tools of Akeeba,
 * on the owner's own server): once the `.htaccess` was unpacked the next step answered "Access forbidden", the page seemed to hang, and the tool
 * could not be deleted. The tool has to stay reachable until it is deleted, and the site's file has to be the site's own again afterwards.
 */
#[Group('fresh')]
final class RestoreGuardHostTest extends IntegrationTestCase
{
    use TempDirectory;

    private const string KEY = 'a long secret key for the restore';

    /** What Admin Tools writes (the part that matters): no direct request for a PHP file. */
    private const string HTACCESS = "RewriteEngine On\nRewriteCond %{REQUEST_FILENAME} (\\.php)\$\nRewriteRule (.*\\.php)\$ - [F]\n";

    protected function setUp(): void
    {
        parent::setUp();
        if (!FreshHost::available()) {
            self::markTestSkipped('The host with nothing on it (service "fresh") is not running.');
        }
        FreshHost::reset();
    }

    protected function tearDown(): void
    {
        FreshHost::reset();
        $this->cleanUpTempDirectories();
    }

    public function testTheToolStaysReachableWhileTheSitesHtaccessRefusesEveryPhpFile(): void
    {
        $base = $this->tempDirectory();
        $fixture = new BackupFixture($base . '/fixture');
        file_put_contents($fixture->site . '/.htaccess', self::HTACCESS);
        $docroot = FreshHost::WWW . '/html';
        foreach ((new BackupCatalog($fixture->storage()))->archiveParts($fixture->backup()) as $part) {
            copy($part, $docroot . '/' . basename($part));
        }
        (new RestoreBuilder(dirname(__DIR__, 2)))->build($docroot . '/threadsafe-restore.php');
        file_put_contents($docroot . '/threadsafe-restore-key.php', "<?php return '" . self::KEY . "';");
        foreach (glob($docroot . '/*') ?: [] as $file) {
            chmod($file, 0644);
        }
        FreshHost::ownedByTheWebServer($docroot);
        $host = new FreshHost();

        $page = $host->get('/threadsafe-restore.php');
        self::assertSame(200, $page['status'], $page['body']);
        $login = $host->request('POST', '/threadsafe-restore.php', ['action' => 'login', 'key' => self::KEY] + (str_contains($page['body'], 'name="plain"') ? ['plain' => '1'] : []));
        self::assertSame(303, $login['status'], $login['body']);
        $backup = $host->get('/threadsafe-restore.php?step=backup');
        self::assertSame(1, preg_match('/name="choice" value="([^"]+)"/', $backup['body'], $choice), 'the backup is offered');
        $chosen = $choice[1] ?? '';
        $host->request('POST', '/threadsafe-restore.php', ['action' => 'choose-backup', 'choice' => $chosen, 'path' => '', 'csrf' => $host->csrf()]);
        $host->request('POST', '/threadsafe-restore.php', ['action' => 'save-target', 'root' => '/var/www/html', 'allow_leftovers' => '1', 'csrf' => $host->csrf()]);
        $summary = $host->get('/threadsafe-restore.php?step=summary');
        self::assertStringContainsString('Restore now', $summary['body']);
        $host->request('POST', '/threadsafe-restore.php', ['action' => 'start', 'csrf' => $host->csrf()]);

        // every step is a request of its own; the .htaccess of the site is unpacked in the middle of them
        $answer = ['status' => 'running'];
        for ($tick = 1; $tick <= 400 && $answer['status'] === 'running'; ++$tick) {
            $response = $host->request('POST', '/threadsafe-restore.php', ['action' => 'tick', 'format' => 'json', 'csrf' => $host->csrf()]);
            self::assertSame(200, $response['status'], 'step ' . $tick . ' was answered with HTTP ' . $response['status'] . ': ' . substr($response['body'], 0, 200));
            $answer = json_decode($response['body'], true);
            self::assertIsArray($answer, $response['body']);
            self::assertContains($answer['status'], ['running', 'finished'], $response['body']);
        }
        self::assertSame('finished', $answer['status']);

        $content = (string) file_get_contents($docroot . '/.htaccess');
        self::assertStringStartsWith('# BEGIN ThreadSafe restore tool', $content);
        self::assertStringEndsWith(self::HTACCESS, $content);
        $other = $host->get('/index.php');
        self::assertSame(403, $other['status'], 'the rule of the site works as it did: another PHP file is refused');

        $done = $host->get('/threadsafe-restore.php?step=done');
        self::assertSame(200, $done['status'], 'the page of the end is reachable');
        self::assertStringContainsString('Delete the tool now', $done['body']);
        $gone = $host->request('POST', '/threadsafe-restore.php', ['action' => 'cleanup', 'csrf' => $host->csrf()]);
        self::assertSame(200, $gone['status']);
        self::assertStringContainsString('The tool is gone', $gone['body']);
        self::assertMatchesRegularExpression('~<a class="button" href="http://[^"/]+/">Open the site</a>~', $gone['body'], 'the page offers the restored site');

        self::assertSame(self::HTACCESS, file_get_contents($docroot . '/.htaccess'), 'the file is the site\'s own again');
        self::assertFileDoesNotExist($docroot . '/threadsafe-restore.php');
    }
}
