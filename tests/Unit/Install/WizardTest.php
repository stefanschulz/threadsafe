<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Install\Wizard;
use ThreadSafe\Tests\Support\ReleasePackage;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\Request;
use ThreadSafe\Web\Response;

/**
 * The pages of the installer: what a visitor without the key sees, what the person is refused, and the checks before anything is written.
 * (The whole installation through the pages, from the file alone, is in InstallBuilderTest.)
 */
final class WizardTest extends TestCase
{
    use TempDirectory;

    private string $docroot = '';

    private string $script = '';

    private Wizard $wizard;

    protected function setUp(): void
    {
        $base = $this->tempDirectory();
        $this->docroot = $base . '/public_html';
        mkdir($this->docroot);
        // the package is the file the wizard reads; a plain package archive stands in for install.php
        $this->script = $this->docroot . '/install.php';
        copy(ReleasePackage::build($base), $this->script);
        $this->wizard = new Wizard($this->script, null, static fn (): Budget => new Budget(0.0));
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @param array<string, string> $post
     * @param array<string, string> $query
     */
    private function send(string $method = 'GET', array $post = [], array $query = [], ?string $token = null): Response
    {
        $cookies = $token === null ? [] : [Wizard::COOKIE => $token];

        return $this->wizard->handle(new Request($method, $query, $post, $cookies, false, '127.0.0.1', '/install.php'));
    }

    /**
     * Logs in.
     *
     * @return array{string, string} the cookie and the CSRF token
     */
    private function login(): array
    {
        file_put_contents($this->docroot . '/install-key.php', "<?php return 'a long secret key';");
        $response = $this->send('POST', ['action' => 'login', 'key' => 'a long secret key']);
        self::assertSame(303, $response->status);
        preg_match('/threadsafe_install=([0-9a-f]{64})/', (string) $response->header('Set-Cookie'), $cookie);
        $page = $this->send('GET', [], ['step' => 'check'], $cookie[1] ?? '');
        preg_match('/name="csrf" value="([0-9a-f]+)"/', $page->body, $csrf);

        return [$cookie[1] ?? '', $csrf[1] ?? ''];
    }

    public function testWithoutAKeyFileNothingHappens(): void
    {
        $response = $this->send();

        self::assertSame(403, $response->status);
        self::assertStringContainsString('does nothing until you give it a key', $response->body);
        self::assertDirectoryDoesNotExist($this->docroot . '/.threadsafe-install');
    }

    public function testAKeyThatIsTooShortIsRefused(): void
    {
        file_put_contents($this->docroot . '/install-key.php', "<?php return 'short';");

        $response = $this->send();

        self::assertSame(403, $response->status);
        self::assertStringContainsString('too short', $response->body);
    }

    public function testTheKeyIsAskedForAndAWrongOneIsRefusedAndSlowedDown(): void
    {
        file_put_contents($this->docroot . '/install-key.php', "<?php return 'a long secret key';");

        self::assertStringContainsString('type="password" name="key"', $this->send()->body);
        $statuses = [];
        for ($i = 0; $i < 5; ++$i) {
            $statuses[] = $this->send('POST', ['action' => 'login', 'key' => 'nope'])->status;
        }

        self::assertSame(403, $statuses[0]);
        self::assertStringContainsString('Wait', $this->send('POST', ['action' => 'login', 'key' => 'a long secret key'])->body, 'even the right key waits after three wrong ones');
    }

    public function testEveryPageNeedsTheLogin(): void
    {
        file_put_contents($this->docroot . '/install-key.php', "<?php return 'a long secret key';");

        foreach (['check', 'run', 'done'] as $step) {
            $page = $this->send('GET', [], ['step' => $step]);
            self::assertSame(200, $page->status);
            self::assertStringContainsString('name="key"', $page->body, $step);
        }
        self::assertStringContainsString('name="key"', $this->send('POST', ['action' => 'cleanup'])->body, 'a cleanup without the login does not delete anything');
        self::assertFileExists($this->script);
    }

    public function testAFormWithoutTheTokenOfTheSessionIsNotAccepted(): void
    {
        [$token] = $this->login();

        $response = $this->send('POST', ['action' => 'install', 'place' => 'outside', 'csrf' => 'wrong'], [], $token);

        self::assertSame(403, $response->status);
        self::assertStringContainsString('The page has expired', $response->body);
        self::assertDirectoryDoesNotExist(dirname($this->docroot) . '/threadsafe');
    }

    public function testTheCheckPageNamesTheVersionAndTheTwoPlaces(): void
    {
        [$token] = $this->login();

        $page = $this->send('GET', [], ['step' => 'check'], $token);

        self::assertStringContainsString('ThreadSafe <strong>0.1.0</strong>', $page->body);
        self::assertStringContainsString('Next to the document root', $page->body);
        self::assertStringContainsString('Below the document root', $page->body);
        self::assertStringContainsString('Weaker choice', $page->body);
        self::assertStringContainsString('<div class="tile">', $page->body, 'each choice is a tile');
        self::assertSame(1, preg_match('#<div class="tile"><label class="pick"><input type="radio" name="place" value="inside"[^>]*>.*?name="inside_ok"#s', $page->body), 'the confirmation stands inside the tile of the weaker place');
        self::assertSame(1, substr_count($page->body, 'name="inside_ok"'));
        self::assertStringContainsString('value="install"', $page->body);
    }

    public function testADocumentRootWithAnIndexPhpBlocksTheInstallation(): void
    {
        [$token] = $this->login();
        file_put_contents($this->docroot . '/index.php', '<?php // theirs');

        $page = $this->send('GET', [], ['step' => 'check'], $token);

        self::assertStringContainsString('&quot;index.php&quot; exists', $page->body);
        self::assertStringNotContainsString('value="install"', $page->body, 'no button while there is a problem');
    }

    public function testAHomeThatIsNotAFullPathIsRefusedBeforeAnythingIsWritten(): void
    {
        [$token, $csrf] = $this->login();

        $page = $this->send('POST', ['csrf' => $csrf, 'action' => 'install', 'place' => 'custom', 'custom' => 'relative/home'], [], $token);

        self::assertStringContainsString('full path', $page->body);
        self::assertDirectoryDoesNotExist($this->docroot . '/.threadsafe-install/runs');
    }

    public function testAHomeBelowTheDocumentRootNeedsTheTickThatSaysWhatItMeans(): void
    {
        [$token, $csrf] = $this->login();

        $refused = $this->send('POST', ['csrf' => $csrf, 'action' => 'install', 'place' => 'inside'], [], $token);
        self::assertStringContainsString('closed by an .htaccess only', $refused->body);
        self::assertFileDoesNotExist(dirname($this->docroot) . '/threadsafe/current.php');

        $accepted = $this->send('POST', ['csrf' => $csrf, 'action' => 'install', 'place' => 'inside', 'inside_ok' => '1'], [], $token);
        self::assertSame(303, $accepted->status);
    }

    public function testNoPlaceChosenIsAMessageNotAnInstallation(): void
    {
        [$token, $csrf] = $this->login();

        $page = $this->send('POST', ['csrf' => $csrf, 'action' => 'install'], [], $token);

        self::assertStringContainsString('Choose where the program goes', $page->body);
    }

    public function testALoginAfterACutOffGoesBackToTheRunThatWasStarted(): void
    {
        [$token, $csrf] = $this->login();
        $this->send('POST', ['csrf' => $csrf, 'action' => 'install', 'place' => 'outside'], [], $token);
        $this->send('POST', ['csrf' => $csrf, 'action' => 'tick', 'format' => 'json'], [], $token);

        $again = $this->send('POST', ['action' => 'login', 'key' => 'a long secret key']);

        self::assertSame('?step=run', $again->header('Location'));
    }

    public function testAFailedStepShowsItsMessageAndCanBeTriedAgain(): void
    {
        [$token, $csrf] = $this->login();
        $this->send('POST', ['csrf' => $csrf, 'action' => 'install', 'place' => 'outside'], [], $token);
        // somebody puts a file there between the check and the installation
        $this->send('POST', ['csrf' => $csrf, 'action' => 'tick', 'format' => 'json'], [], $token);
        file_put_contents($this->docroot . '/index.php', '<?php // theirs');
        $tick = null;
        for ($i = 0; $i < 30 && ($tick['status'] ?? '') !== 'failed'; ++$i) {
            $tick = json_decode($this->send('POST', ['csrf' => $csrf, 'action' => 'tick', 'format' => 'json'], [], $token)->body, true);
        }

        self::assertIsArray($tick);
        self::assertSame('failed', $tick['status']);
        self::assertStringContainsString('appeared in the meantime', (string) $tick['error']);
        self::assertFileDoesNotExist(dirname($this->docroot) . '/threadsafe/current.php', 'the pointer is not written');

        unlink($this->docroot . '/index.php');
        $this->send('POST', ['csrf' => $csrf, 'action' => 'retry'], [], $token);
        for ($i = 0; $i < 30 && ($tick['status'] ?? '') !== 'finished'; ++$i) {
            $tick = json_decode($this->send('POST', ['csrf' => $csrf, 'action' => 'tick', 'format' => 'json'], [], $token)->body, true);
        }
        self::assertSame('finished', $tick['status']);
        self::assertFileExists(dirname($this->docroot) . '/threadsafe/current.php');
    }
}
