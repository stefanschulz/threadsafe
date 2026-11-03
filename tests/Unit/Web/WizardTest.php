<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Restore\Field;
use ThreadSafe\Restore\ToolGuard;
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

    /**
     * A backup of a small site, uploaded next to the tool.
     *
     * @param string|null $recipient a public key: the backup is then encrypted for it
     * @param string|null $htaccess  the content of an .htaccess of the site
     */
    private function upload(?string $recipient = null, ?string $htaccess = null): void
    {
        $fixture = new BackupFixture($this->base . '/fixture');
        if ($htaccess !== null) {
            file_put_contents($fixture->site . '/.htaccess', $htaccess);
        }
        if ($recipient !== null) {
            $fixture->profiles->save($fixture->profiles->load('blog')->with(['encryption' => ['recipients' => [$recipient]]]));
        }
        foreach ((new BackupCatalog($fixture->storage()))->archiveParts($fixture->backup()) as $part) {
            copy($part, $this->web . '/' . basename($part));
            $this->parts[] = $this->web . '/' . basename($part);
        }
        file_put_contents($this->web . '/threadsafe-restore.php', '<?php // the tool');
    }

    private function key(string $key = self::KEY): void
    {
        file_put_contents($this->web . '/threadsafe-restore-key.php', "<?php return '$key';");
    }

    private function wizard(): Wizard
    {
        return new Wizard($this->web, PluginRegistry::load(), 'threadsafe-restore.php', fn (): int => $this->now, static fn (): Budget => new Budget(0.0));
    }

    private function loggedIn(bool $https = false, string $address = '127.0.0.1', string $host = ''): WizardClient
    {
        $client = new WizardClient($this->wizard(), $address, $https, $host);
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
        self::assertStringContainsString('threadsafe-restore-key.php', $response->body);
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
        self::assertFileExists($this->web . '/threadsafe-restore.php', 'nothing was deleted');
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
        self::assertFileExists($this->web . '/threadsafe-restore.php');

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
            self::assertStringContainsString('img-src data:', $policy, 'the favicon is a data address');
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
        self::assertFileExists($this->web . '/threadsafe-restore.php');

        $gone = $client->post(['action' => 'cleanup']);
        self::assertStringContainsString('The tool is gone', $gone->body);
        self::assertFileDoesNotExist($this->web . '/threadsafe-restore.php');
        self::assertFileDoesNotExist($this->web . '/threadsafe-restore-key.php');
        self::assertDirectoryDoesNotExist($this->web . '/.threadsafe-restore');
        foreach ($this->parts as $part) {
            self::assertFileExists($part, 'the backup stays unless the person asks');
        }
    }

    public function testTheSummaryAsksForAFileOfTheStateDirectoryAndARestoreIsRefusedWhileItIsServed(): void
    {
        $this->upload();
        $this->key();
        $root = $this->base . '/restored';
        $client = $this->loggedIn();
        $client->post(['action' => 'choose-backup', 'choice' => basename($this->parts[0]), 'path' => '']);
        $client->post(['action' => 'save-target', 'root' => $root]);

        $summary = $client->get(['step' => 'summary']);
        $probes = glob($this->web . '/.threadsafe-restore/probe-*.txt') ?: [];
        self::assertCount(1, $probes, 'the database is unpacked into this directory: the page looks at it');
        $token = substr((string) file_get_contents($probes[0]), strlen('ThreadSafe probe '));
        self::assertStringContainsString($token, $summary->body, 'the script compares what comes back with the token');
        self::assertStringContainsString('".threadsafe-restore/' . basename($probes[0]) . '"', $summary->body);
        self::assertStringContainsString('location ~ /\.threadsafe-restore', $summary->body, 'the page says what to do on nginx');

        $answer = $client->post(['action' => 'probe', 'result' => 'rubbish']);
        self::assertStringContainsString('"recorded":false', $answer->body);
        self::assertStringContainsString('"recorded":true', $client->post(['action' => 'probe', 'result' => 'reachable'])->body);
        $again = $client->get(['step' => 'summary']);
        self::assertStringContainsString('Yes: the test file can be fetched', $again->body);
        self::assertStringContainsString('id="start" disabled', $again->body);
        self::assertCount(1, glob($this->web . '/.threadsafe-restore/probe-*.txt') ?: [], 'the same file, not a new one per look');

        $refused = $client->post(['action' => 'start']);
        self::assertStringContainsString('can be fetched through the web server', $refused->body);
        self::assertStringNotContainsString('Keep this page open', $refused->body);
        self::assertDirectoryDoesNotExist($root, 'nothing is restored while the files of the restore are served');

        $client->post(['action' => 'probe', 'result' => 'closed']);
        self::assertStringContainsString('Keep this page open', $client->post(['action' => 'start'])->body);
    }

    /**
     * @return array<string, mixed> the last answer of the run
     */
    private function restoreToTheEnd(WizardClient $client, string $newDomain = ''): array
    {
        $client->post(['action' => 'choose-backup', 'choice' => basename($this->parts[0]), 'path' => '']);
        $client->post(['action' => 'save-target', 'root' => $this->base . '/restored'] + ($newDomain === '' ? [] : ['f_to' => $newDomain, 'f_from' => 'old.example']));
        $client->post(['action' => 'start']);
        $answer = ['status' => 'running'];
        for ($tick = 1; $tick <= 200 && $answer['status'] === 'running'; ++$tick) {
            $answer = json_decode($client->post(['action' => 'tick', 'format' => 'json'])->body, true);
        }

        return $answer;
    }

    public function testWhenTheToolIsGoneTheLastPageOffersTheRestoredSite(): void
    {
        // field test: after the restore and the deletion of the tool the person has to type the address of the site by hand
        $this->upload();
        $this->key();
        $client = $this->loggedIn(true, '127.0.0.1', 'tool.example');

        self::assertSame('finished', $this->restoreToTheEnd($client, 'New.Example')['status']);
        self::assertStringNotContainsString('class="button"', $client->get(['step' => 'done'])->body, 'while the tool is there the site is not offered: delete the tool first');
        $gone = $client->post(['action' => 'cleanup']);

        self::assertStringContainsString('The tool is gone', $gone->body);
        self::assertStringContainsString('<a class="button" href="https://new.example/">Open the site</a>', $gone->body);
    }

    public function testTheLastPageOffersTheHostOfThePageWhenTheNewDomainWasLeftEmpty(): void
    {
        $this->upload();
        $this->key();
        $client = $this->loggedIn(false, '127.0.0.1', 'tool.example:8080');

        self::assertSame('finished', $this->restoreToTheEnd($client)['status']);
        $gone = $client->post(['action' => 'cleanup']);

        self::assertStringContainsString('href="http://tool.example:8080/">Open the site</a>', $gone->body);
    }

    public function testTheLastPageOffersNoButtonsWhenNoAddressIsKnown(): void
    {
        $this->upload();
        $this->key();
        $client = $this->loggedIn();

        self::assertSame('finished', $this->restoreToTheEnd($client)['status']);
        $gone = $client->post(['action' => 'cleanup']);

        self::assertStringContainsString('The tool is gone', $gone->body);
        self::assertStringNotContainsString('class="button', $gone->body, 'no Host header and no new domain: no address to offer');
    }

    public function testTheHtaccessOfARestoredSiteDoesNotLockTheToolOut(): void
    {
        // field test: the Admin Tools .htaccess refuses every PHP file but index.php; once it was unpacked the next step answered "Access forbidden"
        // and the restore stopped, without a way to press "Delete the tool". The tool is let through until it is deleted.
        $htaccess = "RewriteEngine On\nRewriteCond %{REQUEST_FILENAME} (\\.php)\$\nRewriteRule (.*\\.php)\$ - [F]\n";
        $this->upload(htaccess: $htaccess);
        $this->key();
        $client = $this->loggedIn();
        $client->post(['action' => 'choose-backup', 'choice' => basename($this->parts[0]), 'path' => '']);
        $client->post(['action' => 'save-target', 'root' => $this->web, 'allow_leftovers' => '1']);
        $client->post(['action' => 'start']);

        for ($tick = 1; $tick <= 200; ++$tick) {
            $answer = json_decode($client->post(['action' => 'tick', 'format' => 'json'])->body, true);
            self::assertContains($answer['status'], ['running', 'finished'], (string) json_encode($answer));
            if ($answer['status'] === 'finished') {
                break;
            }
        }
        self::assertSame('finished', $answer['status']);

        $content = (string) file_get_contents($this->web . '/.htaccess');
        self::assertStringStartsWith(ToolGuard::BEGIN, $content, 'the tool stays reachable while the page of the end is shown');
        self::assertStringContainsString('RewriteRule ^threadsafe-restore\\.php$ - [L]', $content);
        self::assertStringEndsWith($htaccess, $content);

        $client->get(['step' => 'done']);
        $client->post(['action' => 'cleanup']);

        self::assertSame($htaccess, file_get_contents($this->web . '/.htaccess'), 'the file is the site\'s own again when the tool is gone');
        self::assertFileDoesNotExist($this->web . '/threadsafe-restore.php');
    }

    public function testARestoreIntoAnotherDirectoryLeavesTheHtaccessAlone(): void
    {
        $htaccess = "RewriteEngine On\n";
        $this->upload(htaccess: $htaccess);
        $this->key();
        $client = $this->loggedIn();
        $client->post(['action' => 'choose-backup', 'choice' => basename($this->parts[0]), 'path' => '']);
        $client->post(['action' => 'save-target', 'root' => $this->base . '/restored']);
        $client->post(['action' => 'start']);
        for ($tick = 1; $tick <= 200 && (json_decode($client->post(['action' => 'tick', 'format' => 'json'])->body, true)['status'] ?? '') !== 'finished'; ++$tick) {
        }

        self::assertSame($htaccess, file_get_contents($this->base . '/restored/.htaccess'), 'the tool is not in that directory: nothing to keep reachable');
    }

    public function testTheScriptOfTheRunPageSaysWhenTheServerRefusesAStepInsteadOfWaitingForever(): void
    {
        $this->upload();
        $this->key();
        $client = $this->loggedIn();
        $client->post(['action' => 'choose-backup', 'choice' => basename($this->parts[0]), 'path' => '']);
        $client->post(['action' => 'save-target', 'root' => $this->base . '/restored']);

        $run = $client->post(['action' => 'start']);

        // field test: the page showed "No answer from the server; trying again ..." for ever when a step got an HTML page with HTTP 403
        self::assertStringContainsString("type.indexOf('json') === -1", $run->body, 'an answer that is not data is not taken for one');
        self::assertStringContainsString('the web server answered HTTP', $run->body);
        self::assertStringContainsString('HTTP 403 usually means that a rule of the web server refuses this file', $run->body);
        self::assertStringContainsString('Rename the .htaccess in this directory', $run->body);
        self::assertStringContainsString('press "Continue"', $run->body, 'the way out after a refusal is the button that is always there');
    }

    public function testTheRunPageOffersOnlyTheButtonsThatMakeSenseAndShowsTheClock(): void
    {
        $this->upload();
        $this->key();
        $client = $this->loggedIn();
        $client->post(['action' => 'choose-backup', 'choice' => basename($this->parts[0]), 'path' => '']);
        $client->post(['action' => 'save-target', 'root' => $this->base . '/restored']);

        $run = $client->post(['action' => 'start']);

        // field test: "Continue" and "Try the failed step again" could be pressed whenever; nothing showed that a step was working
        self::assertStringContainsString('id="go">Continue</button>', $run->body, 'nothing failed: "Continue" can be pressed (the script holds it while a step works)');
        self::assertStringContainsString('id="retry" disabled>Try the failed step again</button>', $run->body, 'nothing failed: there is nothing to try again');
        self::assertStringContainsString('<p id="clock" class="clock" data-elapsed="0">All together: 0:0', $run->body, 'the clock has a line of its own, apart from the note');
        self::assertStringContainsString('id="note"', $run->body);
        self::assertStringContainsString("var csrf = ", $run->body);
        self::assertStringContainsString("statusUrl = \"?step=status\"", $run->body);
        self::assertStringContainsString('function buttons(failed) { go.disabled = working || failed; retry.disabled = !failed || working; }', $run->body);
    }

    public function testTheStatusAddressSaysWhatTheWorkingStepIsDoing(): void
    {
        $this->upload();
        $this->key();
        $client = $this->loggedIn();
        $client->post(['action' => 'choose-backup', 'choice' => basename($this->parts[0]), 'path' => '']);
        $client->post(['action' => 'save-target', 'root' => $this->base . '/restored']);
        $client->post(['action' => 'start']);

        $idle = json_decode($client->get(['step' => 'status'])->body, true);

        self::assertIsArray($idle);
        self::assertFalse($idle['working'], 'no step is working between two requests');
        self::assertSame('', $idle['note']);
        self::assertSame(0, $idle['seconds']);
        self::assertGreaterThanOrEqual(0, $idle['elapsed']);
    }

    public function testTheTargetPageOffersToDeleteTheExistingTablesWithAWarningAndRemembersTheChoice(): void
    {
        $this->upload();
        $this->key();
        $client = $this->loggedIn();
        $client->post(['action' => 'choose-backup', 'choice' => basename($this->parts[0]), 'path' => '']);

        $page = $client->get(['step' => 'target']);

        // field test: "the database must be empty" - what if it is not? The answer is an option, off by default, with a warning
        self::assertStringContainsString('name="drop_tables" value="1">Delete the existing tables of the database first', $page->body);
        self::assertStringContainsString('deletes every table and view</strong> of the database whose name begins with the table prefix', $page->body);
        self::assertStringContainsString('It cannot be undone.', $page->body);
        self::assertStringNotContainsString('name="drop_tables" value="1" checked', $page->body, 'off by default');

        $client->post(['action' => 'save-target', 'root' => $this->base . '/restored', 'drop_tables' => '1']);
        self::assertStringContainsString('name="drop_tables" value="1" checked', $client->get(['step' => 'target'])->body, 'the choice is kept when the page is shown again');
    }

    public function testTheNewDomainStartsWithTheHostThePageIsServedAt(): void
    {
        $wizard = new Wizard($this->web, PluginRegistry::load(), 'threadsafe-restore.php', fn (): int => $this->now, static fn (): Budget => new Budget(0.0));
        (new WizardClient($wizard, host: 'Restore.Example.org:8443'))->get();
        $proposal = new \ReflectionMethod($wizard, 'defaultOf');

        self::assertSame('restore.example.org:8443', $proposal->invoke($wizard, new Field('to', 'New domain', Field::TEXT, '', proposeHost: true)));
        self::assertSame('', $proposal->invoke($wizard, new Field('to', 'New domain', Field::TEXT, '')), 'a field that does not ask for it starts empty');
        self::assertSame('fixed', $proposal->invoke($wizard, new Field('to', 'New domain', Field::TEXT, 'fixed', proposeHost: true)), 'a default of the type wins');

        (new WizardClient($wizard, host: 'not a host; <script>'))->get();
        self::assertSame('', $proposal->invoke($wizard, new Field('to', 'New domain', Field::TEXT, '', proposeHost: true)), 'a Host header that is no host name is not used');
    }

    public function testAnUnfinishedRestoreGoesOnWhenThePageIsOpenedAgain(): void
    {
        $this->upload();
        $this->key();
        $root = $this->base . '/restored';
        $first = $this->loggedIn();
        $first->post(['action' => 'choose-backup', 'choice' => basename($this->parts[0]), 'path' => '']);
        $first->post(['action' => 'save-target', 'root' => $root]);
        $first->post(['action' => 'start']);
        $begun = json_decode($first->post(['action' => 'tick', 'format' => 'json'])->body, true);
        self::assertSame('running', $begun['status']);

        // the browser is closed: a new one logs in with the key and finds the restore where it stopped
        $second = new WizardClient($this->wizard());
        $second->get();
        $page = $second->post(['action' => 'login', 'key' => self::KEY], [], true, false);

        self::assertStringContainsString('Keep this page open', $page->body);
        do {
            $answer = json_decode($second->post(['action' => 'tick', 'format' => 'json'])->body, true);
        } while ($answer['status'] === 'running');
        self::assertSame('finished', $answer['status']);
        self::assertSame('<?php echo 1;', file_get_contents($root . '/index.php'));
        self::assertStringContainsString('The backup is restored', $second->get(['step' => 'done'])->body);
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
        self::assertFileExists($this->web . '/threadsafe-restore.php');

        $client->post(['action' => 'start']);
        $client->post(['action' => 'tick', 'format' => 'json']);
        $client->post(['action' => 'cleanup']);
        self::assertFileExists($this->web . '/threadsafe-restore.php', 'a restore that is not finished keeps its tool');
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
    // ----------------------------------------------------------------------------------------------- encrypted backups

    public function testAnEncryptedBackupIsOfferedAsSuchAndAsksForItsKey(): void
    {
        $pair = AgeKeys::generate();
        $this->upload($pair['public']);
        $this->key();
        $client = $this->loggedIn();

        $page = $client->get(['step' => 'backup']);
        self::assertStringContainsString('encrypted', $page->body);
        self::assertStringContainsString('name="unlock"', $page->body);
        self::assertStringContainsString('type="password"', $page->body);

        $without = $client->post(['action' => 'choose-backup', 'choice' => basename($this->parts[0]), 'path' => '', 'unlock' => '']);
        self::assertStringContainsString('is encrypted', $without->body);
        self::assertStringContainsString('Choose the backup', $without->body, 'it stays on the first page');

        $stranger = AgeKeys::generate();
        $wrong = $client->post(['action' => 'choose-backup', 'choice' => basename($this->parts[0]), 'path' => '', 'unlock' => $stranger['secret']]);
        self::assertStringContainsString('Choose the backup', $wrong->body);
        self::assertStringNotContainsString($stranger['secret'], $wrong->body, 'what was typed is never shown again');

        $typo = $client->post(['action' => 'choose-backup', 'choice' => basename($this->parts[0]), 'path' => '', 'unlock' => 'AGE-SECRET-KEY-1NOTAKEY']);
        self::assertStringContainsString('Choose the backup', $typo->body);

        $right = $client->post(['action' => 'choose-backup', 'choice' => basename($this->parts[0]), 'path' => '', 'unlock' => $pair['secret']]);
        self::assertStringContainsString('Requirements', $right->body);
        self::assertStringContainsString('4 files', $right->body);
    }

    public function testAnEncryptedSiteIsRestoredAndNeitherTheSessionNorTheRunKeepsTheKey(): void
    {
        $pair = AgeKeys::generate();
        $this->upload($pair['public']);
        $this->key();
        $root = $this->base . '/restored';
        $client = $this->loggedIn();
        $client->post(['action' => 'choose-backup', 'choice' => basename($this->parts[0]), 'path' => '', 'unlock' => $pair['secret']]);
        $client->post(['action' => 'save-target', 'root' => $root]);
        self::assertStringContainsString('Restore now', $client->get(['step' => 'summary'])->body);

        $client->post(['action' => 'start']);
        for ($tick = 1; $tick <= 300; ++$tick) {
            $answer = json_decode($client->post(['action' => 'tick', 'format' => 'json'])->body, true);
            self::assertContains($answer['status'], ['running', 'finished'], (string) json_encode($answer));
            if ($answer['status'] === 'finished') {
                break;
            }
        }

        self::assertSame('finished', $answer['status']);
        self::assertSame('<?php echo 1;', file_get_contents($root . '/index.php'));
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->web, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isFile() && !str_ends_with($file->getFilename(), '.age')) {
                self::assertStringNotContainsString($pair['secret'], (string) file_get_contents($file->getPathname()), 'the secret key is in no file of the tool: ' . $file->getPathname());
            }
        }

        $client->post(['action' => 'cleanup', 'archives' => '1']);
        foreach ($this->parts as $part) {
            self::assertFileDoesNotExist($part, 'the encrypted parts are deleted on request, too');
            self::assertFileDoesNotExist(preg_replace('/(-p\d+)?\.zip\.age$/', '.json', $part) ?? $part);
        }
    }
}
