<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Tests\Support\JoomlaSite;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Tests\Support\ToolClient;
use ThreadSafe\Web\BackupWizard;
use ThreadSafe\Web\BackupWorkspace;
use ThreadSafe\Web\Response;

/**
 * The wizard of the backup tool answering requests, without a web server: the key, the security headers, the site, the options with the key pair,
 * the summary with the probe.
 */
final class BackupWizardTest extends TestCase
{
    use TempDirectory;

    private const string KEY = 'correct horse battery';

    private string $base;

    /** The directory the tool is "uploaded" to, and the site. */
    private string $web;

    protected function setUp(): void
    {
        $this->base = $this->tempDirectory();
        $this->web = $this->base . '/site';
        mkdir($this->web);
        file_put_contents($this->web . '/index.php', '<?php echo 1;');
        file_put_contents($this->web . '/threadsafe-backup.php', '<?php // the tool');
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function key(): void
    {
        file_put_contents($this->web . '/threadsafe-backup-key.php', "<?php return '" . self::KEY . "';");
    }

    private function client(): ToolClient
    {
        $wizard = new BackupWizard($this->web, PluginRegistry::load(), 'threadsafe-backup.php', static fn (): int => 1_800_000_000);

        return new ToolClient($wizard->handle(...), BackupWizard::COOKIE, '/tools/threadsafe-backup.php');
    }

    private function loggedIn(): ToolClient
    {
        $this->key();
        $client = $this->client();
        $client->get();
        $response = $client->post(['action' => 'login', 'key' => self::KEY], [], true, false);
        self::assertSame(200, $response->status, $response->body);
        self::assertStringContainsString('Directory of the site', $response->body);

        return $client;
    }

    /** The site step done with "no database". */
    private function siteDone(ToolClient $client): void
    {
        $response = $client->post(['action' => 'save-site', 'root' => $this->web, 'type' => 'auto', 'no_db' => '1']);
        self::assertStringContainsString('Who may open this backup?', $response->body);
    }

    private function profile(): SiteProfile
    {
        return BackupWorkspace::in($this->web)->profiles()->load('site');
    }

    /**
     * @param array<string, string> $changes
     *
     * @return array<string, string>
     */
    private function options(array $changes = []): array
    {
        return [...['action' => 'save-options', 'mode' => 'none', 'plain' => '1', 'partMb' => '500', 'exclude' => '', 'note' => '', 'storage' => ''], ...$changes];
    }

    // ------------------------------------------------------------------------------------------------- the key

    public function testWithoutAKeyFileItOnlyExplainsAndWritesNothing(): void
    {
        $response = $this->client()->get();

        self::assertSame(403, $response->status);
        self::assertStringContainsString('does nothing until you give it a key', $response->body);
        self::assertStringContainsString('threadsafe-backup-key.php', $response->body);
        self::assertDirectoryDoesNotExist($this->web . '/.threadsafe-backup');
    }

    public function testAWrongKeyIsRefusedAndTheRightOneLogsIn(): void
    {
        $this->key();
        $client = $this->client();
        $client->get();

        $wrong = $client->post(['action' => 'login', 'key' => 'nope'], [], true, false);
        self::assertSame(403, $wrong->status);

        $right = $client->post(['action' => 'login', 'key' => self::KEY], [], true, false);
        self::assertSame(200, $right->status);
        self::assertStringContainsString('Directory of the site', $right->body);
    }

    public function testEveryAnswerCarriesTheSecurityHeaders(): void
    {
        $this->key();
        $response = $this->client()->get();

        $headers = implode("\n", $response->headers);
        self::assertStringContainsString('Cache-Control: no-store', $headers);
        self::assertStringContainsString('X-Frame-Options: DENY', $headers);
        self::assertStringContainsString("default-src 'none'", $headers);
    }

    public function testAPostWithoutTheTokenIsRefused(): void
    {
        $client = $this->loggedIn();

        $response = $client->post(['action' => 'save-site', 'root' => $this->web], [], true, false);

        self::assertSame(403, $response->status);
        self::assertStringContainsString('expired', $response->body);
    }

    public function testTheLaterStepsAreNotReachableBeforeTheEarlierOnes(): void
    {
        $client = $this->loggedIn();

        self::assertStringContainsString('Directory of the site', $client->get(['step' => 'options'])->body);
        self::assertStringContainsString('Directory of the site', $client->get(['step' => 'summary'])->body);
    }

    // ------------------------------------------------------------------------------------------------- the site

    public function testTheFirstPageAlreadyLooksAtTheDirectoryOfTheTool(): void
    {
        $page = $this->loggedIn()->get(['step' => 'site'])->body;

        self::assertStringContainsString('Other website', $page);
        self::assertStringContainsString(htmlspecialchars((string) realpath($this->web)), $page);
        self::assertStringContainsString('name="db_name"', $page, 'a website of no known kind has no database access to read');
    }

    public function testASiteOfAKnownKindIsRecognisedAndItsDatabaseAccessIsNotAsked(): void
    {
        JoomlaSite::build($this->web);
        $page = $this->loggedIn()->get(['step' => 'site'])->body;

        self::assertStringContainsString('Joomla', $page);
        self::assertStringContainsString('read from the site', $page);
        self::assertStringNotContainsString('name="db_name"', $page);
    }

    public function testADirectoryTheToolIsNotInIsRefused(): void
    {
        mkdir($this->base . '/other');
        $client = $this->loggedIn();

        $response = $client->post(['action' => 'save-site', 'root' => $this->base . '/other', 'type' => 'auto', 'no_db' => '1']);

        self::assertStringContainsString('or one above it', $response->body);
        self::assertStringContainsString('Directory of the site', $response->body, 'the page stays');
    }

    public function testASiteWithoutADatabaseNeedsOneOrTheTick(): void
    {
        $client = $this->loggedIn();

        $missing = $client->post(['action' => 'save-site', 'root' => $this->web, 'type' => 'auto']);
        self::assertStringContainsString('Enter the database name', $missing->body);

        $port = $client->post(['action' => 'save-site', 'root' => $this->web, 'type' => 'auto', 'db_name' => 'x', 'db_user' => 'y', 'db_port' => 'many']);
        self::assertStringContainsString('port must be a number', $port->body);
    }

    public function testTheSiteIsStoredAsTheProfileOfTheTool(): void
    {
        $client = $this->loggedIn();
        $this->siteDone($client);

        $profile = $this->profile();
        self::assertSame('site', $profile->id);
        self::assertSame('generic', $profile->type);
        self::assertSame(realpath($this->web), $profile->path);
        self::assertContains('/threadsafe-backup.php', $profile->excludePaths);
        self::assertContains('/threadsafe-backup-key.php', $profile->excludePaths);
        self::assertContains('/.threadsafe-backup/', $profile->excludePaths);
        self::assertMatchesRegularExpression('~/\.threadsafe-backup/storage-[0-9a-f]{32}$~', $profile->storage);
    }

    public function testADatabaseThatCannotBeReachedStopsTheStep(): void
    {
        $client = $this->loggedIn();

        $response = $client->post(['action' => 'save-site', 'root' => $this->web, 'type' => 'auto', 'db_host' => '127.0.0.1', 'db_port' => '1', 'db_name' => 'x', 'db_user' => 'y', 'db_password' => 'z']);

        self::assertStringContainsString('cannot be backed up like this', $response->body);
        self::assertStringContainsString('Directory of the site', $response->body);
        self::assertFalse(BackupWorkspace::in($this->web)->profiles()->exists('site'), 'a site that fails its test is not stored');
    }

    // ------------------------------------------------------------------------------------------------- the options

    public function testTheOptionsOfferEncryptionByDefault(): void
    {
        $client = $this->loggedIn();
        $this->siteDone($client);

        $page = $client->get(['step' => 'options'])->body;

        self::assertMatchesRegularExpression('~<input type="radio" name="mode" value="pair" checked>~', $page);
        self::assertStringContainsString('value="500"', $page);
    }

    public function testWithoutAMadePairTheDefaultCannotBeSaved(): void
    {
        $client = $this->loggedIn();
        $this->siteDone($client);

        $response = $client->post($this->options(['mode' => 'pair']));

        self::assertStringContainsString('Make the key pair first', $response->body);
    }

    public function testTheSecretKeyIsShownOnceAndNeverStored(): void
    {
        $client = $this->loggedIn();
        $this->siteDone($client);

        $shown = $client->post($this->options(['action' => 'make-keys', 'mode' => 'pair']))->body;
        self::assertMatchesRegularExpression('~AGE-SECRET-KEY-1[A-Z0-9]+~', $shown);
        $public = [1 => $this->found('~Public key: <code>(age1[a-z0-9]+)</code>~', $shown)];

        $without = $client->post(['action' => 'use-keys', 'public' => $public[1]]);
        self::assertStringContainsString('secret key must be stored first', $without->body);

        // a new pair, this time taken
        $shown = $client->post($this->options(['action' => 'make-keys', 'mode' => 'pair']))->body;
        $public = [1 => $this->found('~Public key: <code>(age1[a-z0-9]+)</code>~', $shown)];
        $secret = [1 => $this->found('~(AGE-SECRET-KEY-1[A-Z0-9]+)~', $shown)];
        $page = $client->post(['action' => 'use-keys', 'public' => $public[1], 'stored' => '1']);
        self::assertStringContainsString('A key pair is made', $page->body);
        self::assertStringNotContainsString('AGE-SECRET-KEY', $page->body, 'it is never shown again');
        self::assertStringNotContainsString('AGE-SECRET-KEY', $client->get(['step' => 'options'])->body);

        $client->post($this->options(['mode' => 'pair']));
        self::assertSame([$public[1]], $this->profile()->encryption->recipients ?? []);

        foreach ($this->filesOf($this->web . '/.threadsafe-backup') as $file) {
            self::assertStringNotContainsString($secret[1], (string) file_get_contents($file), "the secret key is in $file");
        }
        self::assertSame($public[1], AgeKeys::publicOf($secret[1]), 'what was shown belongs to the key that is used');
    }

    public function testAPublicKeyThatIsTypedIsCheckedAndUsed(): void
    {
        $client = $this->loggedIn();
        $this->siteDone($client);

        $bad = $client->post($this->options(['mode' => 'recipient', 'recipient' => 'age1nonsense']));
        self::assertStringContainsString('not a public key', $bad->body);

        $pair = AgeKeys::generate();
        $good = $client->post($this->options(['mode' => 'recipient', 'recipient' => $pair['public']]));
        self::assertStringContainsString('Summary', $good->body);
        self::assertSame([$pair['public']], $this->profile()->encryption->recipients ?? []);
    }

    public function testAPassphraseMustBeTheSameTwiceAndStrong(): void
    {
        $client = $this->loggedIn();
        $this->siteDone($client);

        self::assertStringContainsString('not the same', $client->post($this->options(['mode' => 'passphrase', 'passphrase' => 'abc', 'passphrase2' => 'abd']))->body);
        self::assertStringContainsString('short', $client->post($this->options(['mode' => 'passphrase', 'passphrase' => 'abc', 'passphrase2' => 'abc']))->body);

        $good = $client->post($this->options(['mode' => 'passphrase', 'passphrase' => 'a long passphrase of mine', 'passphrase2' => 'a long passphrase of mine']));
        self::assertStringContainsString('Encrypted with a passphrase', $good->body);
        self::assertNull($this->profile()->encryption, 'a passphrase is not a recipient, and is not in the profile');
        foreach ($this->filesOf((string) BackupWorkspace::in($this->web)->existingData() . '/profiles') as $file) {
            self::assertStringNotContainsString('a long passphrase', (string) file_get_contents($file));
        }
    }

    public function testASuggestedPassphraseFillsBothFieldsAndIsAccepted(): void
    {
        $client = $this->loggedIn();
        $this->siteDone($client);

        $page = $client->post($this->options(['action' => 'suggest-passphrase', 'mode' => 'none', 'plain' => '']))->body;
        $m = [1 => $this->found('~name="passphrase" value="([a-z0-9-]{24})"~', $page)];
        self::assertStringContainsString('name="passphrase2" value="' . $m[1] . '"', $page);

        $next = $client->post($this->options(['mode' => 'passphrase', 'passphrase' => $m[1], 'passphrase2' => $m[1], 'plain' => '']));
        self::assertStringContainsString('Encrypted with a passphrase', $next->body);
    }

    public function testNoEncryptionNeedsTheTick(): void
    {
        $client = $this->loggedIn();
        $this->siteDone($client);

        self::assertStringContainsString('Tick the box', $client->post($this->options(['mode' => 'none', 'plain' => '']))->body);

        $done = $client->post($this->options(['mode' => 'none', 'plain' => '1']));
        self::assertStringContainsString('Not encrypted', $done->body);
        self::assertNull($this->profile()->encryption);
    }

    public function testThePatternsAndTheSizeOfAPartAreChecked(): void
    {
        $client = $this->loggedIn();
        $this->siteDone($client);

        self::assertStringContainsString('cannot be used', $client->post($this->options(['exclude' => "cache/\n../outside"]))->body);
        self::assertStringContainsString('whole number of megabytes', $client->post($this->options(['partMb' => '0']))->body);
        self::assertStringContainsString('whole number of megabytes', $client->post($this->options(['partMb' => 'big']))->body);

        $client->post($this->options(['exclude' => "cache/\n\n*.log\n", 'partMb' => '50', 'note' => 'before the update']));
        $profile = $this->profile();
        self::assertSame(['cache/', '*.log'], array_slice($profile->excludePaths, -2));
        self::assertContains('/threadsafe-backup.php', $profile->excludePaths, 'the tool stays out whatever the person writes');
        self::assertSame(50 * 1048576, $profile->partSize);
        self::assertSame('before the update', $profile->note);
    }

    public function testTheDirectoryOfTheArchiveCanBeNamed(): void
    {
        $client = $this->loggedIn();
        $this->siteDone($client);

        self::assertStringContainsString('absolute path', $client->post($this->options(['storage' => 'relative/dir']))->body);

        $elsewhere = $this->base . '/archives';
        $page = $client->post($this->options(['storage' => $elsewhere]));
        self::assertStringContainsString($elsewhere, $page->body);
        self::assertSame($elsewhere, $this->profile()->storage);
        self::assertFileExists($elsewhere . '/.htaccess');
    }

    // ------------------------------------------------------------------------------------------------- the summary and the probe

    public function testTheSummaryWritesAProbeFileIntoTheToolAndTheArchiveDirectoryAndTheScriptAsksForBoth(): void
    {
        $client = $this->loggedIn();
        $this->siteDone($client);
        $summary = $client->post($this->options());

        $storage = $this->profile()->storage;
        $workspace = BackupWorkspace::in($this->web)->directory();
        $probes = glob($storage . '/probe-*.txt') ?: [];
        self::assertCount(1, $probes);
        self::assertCount(1, glob($workspace . '/probe-*.txt') ?: [], 'the settings, the sessions and the run records are in the directory of the tool: it is looked at too');
        $token = substr((string) file_get_contents($probes[0]), strlen('ThreadSafe probe '));
        self::assertStringContainsString($token, $summary->body, 'the script compares what comes back with the token');
        self::assertStringContainsString('".threadsafe-backup/' . basename($storage) . '/' . basename($probes[0]) . '"', $summary->body, 'the address is relative to the tool');
        self::assertStringContainsString('".threadsafe-backup/' . basename($probes[0]) . '"', $summary->body);
    }

    public function testAnArchiveDirectoryOutsideTheToolGetsItsOwnProbeAndTheToolStillIsLookedAt(): void
    {
        $client = $this->loggedIn();
        $this->siteDone($client);
        $elsewhere = $this->base . '/archives';
        $summary = $client->post($this->options(['storage' => $elsewhere]));

        $probes = glob($elsewhere . '/probe-*.txt') ?: [];
        self::assertCount(1, $probes);
        self::assertCount(1, glob(BackupWorkspace::in($this->web)->directory() . '/probe-*.txt') ?: []);
        self::assertStringContainsString('"../archives/' . basename($probes[0]) . '"', $summary->body);
        self::assertStringContainsString('".threadsafe-backup/' . basename($probes[0]) . '"', $summary->body, 'a closed archive directory says nothing about the directory of the tool');
    }

    public function testStartingTheBackupRemovesBothProbeFiles(): void
    {
        $client = $this->loggedIn();
        $this->siteDone($client);
        $client->post($this->options());
        $client->post(['action' => 'probe', 'result' => 'closed']);

        $client->post(['action' => 'start']);

        self::assertSame([], glob($this->profile()->storage . '/probe-*.txt') ?: []);
        self::assertSame([], glob(BackupWorkspace::in($this->web)->directory() . '/probe-*.txt') ?: []);
    }

    public function testTheProbeResultIsKeptAndShown(): void
    {
        $client = $this->loggedIn();
        $this->siteDone($client);
        $client->post($this->options());

        $answer = $client->post(['action' => 'probe', 'result' => 'reachable']);
        self::assertStringContainsString('"recorded":true', $answer->body);
        $summary = $client->get(['step' => 'summary']);
        self::assertStringContainsString('Yes: a test file can be fetched', $summary->body);
        self::assertCount(1, glob($this->profile()->storage . '/probe-*.txt') ?: [], 'the same file, not a new one per look');

        $client->post(['action' => 'probe', 'result' => 'closed']);
        self::assertStringContainsString('No: the web server does not hand the test files out', $client->get(['step' => 'summary'])->body);

        $client->post(['action' => 'probe', 'result' => 'rubbish']);
        self::assertStringContainsString('No: the web server', $client->get(['step' => 'summary'])->body, 'a result that is none of the three changes nothing');
    }

    public function testLoggingOutEndsTheSession(): void
    {
        $client = $this->loggedIn();
        $client->post(['action' => 'logout']);

        self::assertStringContainsString('Key from', $client->get(['step' => 'site'])->body);
    }

    /** The first group of a pattern that must match. */
    private function found(string $pattern, string $text): string
    {
        if (preg_match($pattern, $text, $m) !== 1) {
            self::fail("Nothing matches $pattern");
        }

        return $m[1];
    }

    /**
     * @return list<string>
     */
    private function filesOf(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
