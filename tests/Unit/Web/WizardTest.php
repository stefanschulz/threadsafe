<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Storage\BackupCatalog;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Tests\Support\WizardClient;
use ThreadSafe\Web\Response;
use ThreadSafe\Web\Wizard;

/**
 * The wizard answering requests, without a web server: the key, the security headers, the steps, a restore of a small site from
 * the first page to the deletion of the tool.
 */
final class WizardTest extends TestCase
{
    use TempDirectory;

    private const string KEY = 'correct horse battery';

    private string $base;

    /** The directory the tool is "uploaded" to. */
    private string $web;

    /** @var list<string> the parts of the backup in $web */
    private array $parts = [];

    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->base = $this->tempDirectory();
        $this->web = $this->base . '/web';
        mkdir($this->web);
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /** A backup of a small site, uploaded next to the tool. */
    private function upload(): void
    {
        $fixture = new BackupFixture($this->base . '/fixture');
        foreach ((new BackupCatalog($fixture->storage()))->archiveParts($fixture->backup()) as $part) {
            copy($part, $this->web . '/' . basename($part));
            $this->parts[] = $this->web . '/' . basename($part);
        }
        file_put_contents($this->web . '/restore.php', '<?php // the tool');
    }

    private function key(string $key = self::KEY): void
    {
        file_put_contents($this->web . '/restore-key.php', "<?php return '$key';");
    }

    private function wizard(): Wizard
    {
        return new Wizard($this->web, PluginRegistry::load(), 'restore.php', fn (): int => $this->now, static fn (): Budget => new Budget(0.0));
    }

    private function loggedIn(bool $https = false, string $address = '127.0.0.1'): WizardClient
    {
        $client = new WizardClient($this->wizard(), $address, $https);
        $client->get();
        $fields = ['action' => 'login', 'key' => self::KEY] + ($https || $address === '127.0.0.1' ? [] : ['plain' => '1']);
        $response = $client->post($fields, [], true, false);
        self::assertSame(200, $response->status, $response->body);

        return $client;
    }

    // ----------------------------------------------------------------------------------------------- access

    public function testWithoutAKeyFileTheToolOnlyExplainsAndWritesNothing(): void
    {
        $response = (new WizardClient($this->wizard()))->get();

        self::assertSame(403, $response->status);
        self::assertStringContainsString('restore-key.php', $response->body);
        self::assertStringContainsString('&lt;?php return', $response->body);
        self::assertSame(['.', '..'], scandir($this->web), 'not even the state directory is made');
    }

    public function testAKeyThatIsTooShortIsRefused(): void
    {
        $this->key('abc');

        $response = (new WizardClient($this->wizard()))->get();

        self::assertSame(403, $response->status);
        self::assertStringContainsString('too short', $response->body);
    }

    public function testTheStateDirectoryIsClosedToTheWeb(): void
    {
        $this->key();

        (new WizardClient($this->wizard()))->get();

        $state = $this->web . '/.threadsafe-restore';
        self::assertSame(0700, fileperms($state) & 0777);
        self::assertStringContainsString('Require all denied', (string) file_get_contents($state . '/.htaccess'));
        self::assertFileExists($state . '/index.html');
    }

    public function testNoStepIsShownWithoutLoggingIn(): void
    {
        $this->upload();
        $this->key();
        $client = new WizardClient($this->wizard());

        foreach (['backup', 'requirements', 'target', 'summary', 'run', 'done'] as $step) {
            $response = $client->get(['step' => $step]);
            self::assertStringContainsString('name="key"', $response->body, "step $step shows the key field only");
            self::assertStringNotContainsString($this->parts[0], $response->body);
        }
        $response = $client->post(['action' => 'cleanup'], [], true, false);
        self::assertStringContainsString('name="key"', $response->body);
        self::assertFileExists($this->web . '/restore.php', 'nothing was deleted');
    }

    public function testAWrongKeyIsRefusedAndThrottled(): void
    {
        $this->key();
        $client = new WizardClient($this->wizard());

        for ($i = 0; $i < 3; ++$i) {
            $response = $client->post(['action' => 'login', 'key' => 'wrong'], [], true, false);
            self::assertSame(403, $response->status);
            self::assertStringContainsString('This is not the key.', $response->body);
        }
        $response = $client->post(['action' => 'login', 'key' => self::KEY], [], true, false);

        self::assertSame(403, $response->status);
        self::assertStringContainsString('Wait 5 seconds', $response->body);
        self::assertNull($response->header('Set-Cookie'), 'the right key does not get in during the wait');
    }

    public function testTheCookieIsHttpOnlyAndSameSite(): void
    {
        $this->key();
        $client = new WizardClient($this->wizard(), '127.0.0.1', true);
        $client->get();

        $response = $client->post(['action' => 'login', 'key' => self::KEY], [], false, false);

        self::assertSame(303, $response->status);
        $cookie = (string) $response->header('Set-Cookie');
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Strict', $cookie);
        self::assertStringContainsString('Secure', $cookie);
        self::assertStringContainsString('Path=/;', $cookie);
    }

    public function testPlainHttpFromAnotherHostNeedsAConfirmation(): void
    {
        $this->key();
        $client = new WizardClient($this->wizard(), '203.0.113.7', false);

        $page = $client->get();
        self::assertStringContainsString('not encrypted', $page->body);

        $refused = $client->post(['action' => 'login', 'key' => self::KEY], [], true, false);
        self::assertStringContainsString('Confirm that you accept', $refused->body);
        self::assertNull($refused->header('Set-Cookie'));

        $accepted = $client->post(['action' => 'login', 'key' => self::KEY, 'plain' => '1'], [], true, false);
        self::assertSame(200, $accepted->status);
    }

    public function testLocalAccessNeedsNoConfirmation(): void
    {
        $this->key();

        self::assertStringNotContainsString('not encrypted', (new WizardClient($this->wizard(), '127.0.0.1', false))->get()->body);
    }

    public function testAFormWithoutTheTokenIsRefused(): void
    {
        $this->upload();
        $this->key();
        $client = $this->loggedIn();

        $response = $client->post(['action' => 'cleanup'], [], true, false);

        self::assertSame(403, $response->status);
        self::assertStringContainsString('expired', $response->body);
        self::assertFileExists($this->web . '/restore.php');

        $wrong = $client->post(['action' => 'cleanup', 'csrf' => 'not-the-token'], [], true, false);
        self::assertSame(403, $wrong->status);
    }

    public function testEveryAnswerCarriesTheSecurityHeaders(): void
    {
        $this->key();
        $client = new WizardClient($this->wizard());

        foreach ([$client->get(), $client->post(['action' => 'login', 'key' => 'x'], [], true, false), $this->loggedIn()->get(['step' => 'backup'])] as $response) {
            self::assertSame('no-store', $response->header('Cache-Control'));
            self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
            self::assertSame('DENY', $response->header('X-Frame-Options'));
            self::assertSame('no-referrer', $response->header('Referrer-Policy'));
            $policy = (string) $response->header('Content-Security-Policy');
            self::assertStringContainsString("default-src 'none'", $policy);
            self::assertMatchesRegularExpression("/script-src 'nonce-[0-9a-f]+'/", $policy);
            self::assertStringNotContainsString('unsafe-inline', $policy);
        }
    }

    public function testASessionEndsAfterTwoIdleHours(): void
    {
        $this->upload();
        $this->key();
        $client = $this->loggedIn();
        self::assertStringContainsString('Choose the backup', $client->get(['step' => 'backup'])->body);

        $this->now += 7201;

        self::assertStringContainsString('name="key"', $client->get(['step' => 'backup'])->body);
    }

    // ----------------------------------------------------------------------------------------------- the steps

    public function testTheBackupNextToTheToolIsOfferedAndLookedAt(): void
    {
        $this->upload();
        $this->key();
        $client = $this->loggedIn();

        $page = $client->get(['step' => 'backup']);
        self::assertStringContainsString('Found next to the tool', $page->body);
        self::assertStringContainsString(basename($this->parts[0]), $page->body);

        $requirements = $client->post(['action' => 'choose-backup', 'choice' => basename($this->parts[0]), 'path' => '']);
        self::assertStringContainsString('Requirements', $requirements->body);
        self::assertStringContainsString('What this server offers', $requirements->body);
        self::assertStringContainsString('4 files', $requirements->body);
        self::assertStringContainsString('no database', $requirements->body);
        self::assertStringContainsString('PHP ' . PHP_VERSION, $requirements->body);
    }

    public function testNoBackupIsSaidSoAndABadPathIsExplainedAndEscaped(): void
    {
        $this->key();
        $client = $this->loggedIn();

        self::assertStringContainsString('No backup found next to the tool', $client->get(['step' => 'backup'])->body);

        $bad = $client->post(['action' => 'choose-backup', 'path' => '"><script>alert(1)</script>.zip', 'choice' => '']);
        self::assertStringNotContainsString('<script>alert(1)', $bad->body);
        self::assertStringContainsString('&lt;script&gt;', $bad->body);
        self::assertStringContainsString('does not exist', $bad->body);

        file_put_contents($this->web . '/photos.zip', 'x');
        $other = $client->post(['action' => 'choose-backup', 'path' => 'photos.zip', 'choice' => '']);
        self::assertStringContainsString('not a part of a ThreadSafe backup', $other->body);
    }

    public function testStepsAfterTheFirstNeedABackup(): void
    {
        $this->key();
        $client = $this->loggedIn();

        foreach (['requirements', 'target', 'summary'] as $step) {
            self::assertStringContainsString('Choose the backup', $client->get(['step' => $step])->body);
        }
    }

    public function testASiteWithoutADatabaseAsksForNoDatabase(): void
    {
        $this->upload();
        $this->key();
        $client = $this->loggedIn();
        $client->post(['action' => 'choose-backup', 'choice' => basename($this->parts[0]), 'path' => '']);

        $page = $client->get(['step' => 'target']);

        self::assertStringContainsString('name="root"', $page->body);
        self::assertStringNotContainsString('name="db_host"', $page->body);
        self::assertStringContainsString('name="f_from"', $page->body, 'the fields of the website type');
    }

    // ----------------------------------------------------------------------------------------------- a whole restore

    public function testASiteIsRestoredFromTheFirstPageToTheDeletionOfTheTool(): void
    {
        $this->upload();
        $this->key();
        $root = $this->base . '/restored';
        $client = $this->loggedIn();
        $client->post(['action' => 'choose-backup', 'choice' => basename($this->parts[0]), 'path' => '']);

        $client->post(['action' => 'save-target', 'root' => $root]);
        $summary = $client->get(['step' => 'summary']);
        self::assertStringContainsString('Summary', $summary->body);
        self::assertStringContainsString($root, $summary->body);
        self::assertStringContainsString('Restore now', $summary->body);
        self::assertDirectoryDoesNotExist($root, 'nothing is written before the person says so');

        $run = $client->post(['action' => 'start']);
        self::assertStringContainsString('Keep this page open', $run->body);
        self::assertStringContainsString('<script nonce=', $run->body);
        self::assertStringNotContainsString(' style="', $run->body, 'the content security policy does not allow style attributes');

        for ($tick = 1; $tick <= 200; ++$tick) {
            $answer = json_decode($client->post(['action' => 'tick', 'format' => 'json'])->body, true);
            self::assertContains($answer['status'], ['running', 'finished'], (string) json_encode($answer));

            if ($answer['status'] === 'finished') {
                break;
            }
        }
        self::assertSame('finished', $answer['status']);
        self::assertGreaterThan(5, $tick, 'a tick per step of the restore');
        self::assertSame(100, $answer['percent']);
        self::assertSame('<?php echo 1;', file_get_contents($root . '/index.php'));

        $done = $client->get(['step' => 'done']);
        self::assertStringContainsString('The backup is restored', $done->body);
        self::assertStringContainsString('Delete the tool now', $done->body);
        self::assertFileExists($this->web . '/restore.php');

        $gone = $client->post(['action' => 'cleanup']);
        self::assertStringContainsString('The tool is gone', $gone->body);
        self::assertFileDoesNotExist($this->web . '/restore.php');
        self::assertFileDoesNotExist($this->web . '/restore-key.php');
        self::assertDirectoryDoesNotExist($this->web . '/.threadsafe-restore');
        foreach ($this->parts as $part) {
            self::assertFileExists($part, 'the backup stays unless the person asks');
        }
    }

    public function testTheBackupFilesAreDeletedOnRequest(): void
    {
        $this->upload();
        $this->key();
        $client = $this->loggedIn();
        $client->post(['action' => 'choose-backup', 'choice' => basename($this->parts[0]), 'path' => '']);
        $client->post(['action' => 'save-target', 'root' => $this->base . '/restored']);
        $client->post(['action' => 'start']);
        do {
            $answer = json_decode($client->post(['action' => 'tick', 'format' => 'json'])->body, true);
        } while ($answer['status'] === 'running');

        $client->post(['action' => 'cleanup', 'archives' => '1']);

        foreach ($this->parts as $part) {
            self::assertFileDoesNotExist($part);
        }
    }

    public function testNothingIsDeletedBeforeTheRestoreIsDone(): void
    {
        $this->upload();
        $this->key();
        $client = $this->loggedIn();
        $client->post(['action' => 'choose-backup', 'choice' => basename($this->parts[0]), 'path' => '']);
        $client->post(['action' => 'save-target', 'root' => $this->base . '/restored']);

        $client->post(['action' => 'cleanup']);
        self::assertFileExists($this->web . '/restore.php');

        $client->post(['action' => 'start']);
        $client->post(['action' => 'tick', 'format' => 'json']);
        $client->post(['action' => 'cleanup']);
        self::assertFileExists($this->web . '/restore.php', 'a restore that is not finished keeps its tool');
    }

    public function testATargetThatIsNotEmptyBlocksTheStart(): void
    {
        $this->upload();
        $this->key();
        $root = $this->base . '/occupied';
        mkdir($root);
        file_put_contents($root . '/index.php', 'someone else');
        $client = $this->loggedIn();
        $client->post(['action' => 'choose-backup', 'choice' => basename($this->parts[0]), 'path' => '']);
        $client->post(['action' => 'save-target', 'root' => $root]);

        $summary = $client->get(['step' => 'summary']);
        self::assertStringContainsString('is not empty', $summary->body);
        self::assertStringNotContainsString('Restore now', $summary->body);

        $start = $client->post(['action' => 'start']);
        self::assertStringContainsString('Summary', $start->body, 'back at the summary');
        self::assertSame('someone else', file_get_contents($root . '/index.php'));
    }

    public function testMissingRequiredFieldsAreAskedFor(): void
    {
        $this->upload();
        $this->key();
        $client = $this->loggedIn();
        $client->post(['action' => 'choose-backup', 'choice' => basename($this->parts[0]), 'path' => '']);

        $page = $client->post(['action' => 'save-target', 'root' => '']);

        self::assertStringContainsString('Enter the directory to restore into.', $page->body);
    }

    public function testChoosingAnotherBackupStartsAgain(): void
    {
        $this->upload();
        $this->key();
        $client = $this->loggedIn();
        $client->post(['action' => 'choose-backup', 'choice' => basename($this->parts[0]), 'path' => '']);
        $client->post(['action' => 'save-target', 'root' => $this->base . '/restored']);
        $client->post(['action' => 'start']);

        $client->post(['action' => 'choose-backup', 'choice' => basename($this->parts[0]), 'path' => '']);

        self::assertStringContainsString('Where to restore', $client->get(['step' => 'target'])->body);
        self::assertStringContainsString('Summary', $client->get(['step' => 'summary'])->body);
        self::assertSame(Response::class, $client->get(['step' => 'run'])::class, 'there is no run to show for a backup that was chosen anew');
    }
}
