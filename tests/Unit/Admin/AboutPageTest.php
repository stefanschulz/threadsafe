<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Admin\AboutController;
use ThreadSafe\Core\Version;
use ThreadSafe\Tests\Support\AdminBrowser;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The page "About" and the version under the name in the menu: what the page tells (the version, the license, whose it is, the fonts), the
 * facts for a report of a problem, the license text, and that none of it is there for a person who is not logged in.
 */
final class AboutPageTest extends TestCase
{
    use TempDirectory;

    private AdminBrowser $browser;

    protected function setUp(): void
    {
        $fixture = new BackupFixture($this->tempDirectory());
        $this->browser = new AdminBrowser($fixture->data);
        $this->browser->install();
        $this->browser->login();
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    public function testTheVersionStandsUnderTheNameInTheMenuAndLeadsToThePage(): void
    {
        $page = $this->browser->get('/profiles')->body;

        self::assertStringContainsString('<a class="version side-label" href="/about" title="About ThreadSafe">' . Version::CURRENT . '</a>', $page);
        self::assertSame(1, substr_count($page, 'aria-current="page"'), 'the version is not marked on the other pages');
        self::assertStringContainsString('<footer>ThreadSafe &middot; GPL-3.0-or-later</footer>', $page, 'the footer has no version any more');
        self::assertStringNotContainsString('ThreadSafe ' . Version::CURRENT . '</footer>', $page);
    }

    public function testThePageTellsTheVersionTheLicenseTheOwnerAndTheFonts(): void
    {
        $answer = $this->browser->get('/about');
        $text = html_entity_decode(strip_tags($answer->body));

        self::assertSame(200, $answer->status);
        self::assertStringContainsString('About ThreadSafe ' . Version::CURRENT, $text);
        self::assertStringContainsString('GPL-3.0-or-later', $text);
        self::assertStringContainsString('© ' . AboutController::COPYRIGHT, $text);
        self::assertStringContainsString('without any warranty', $text);
        self::assertStringContainsString('IBM Plex Sans', $text);
        self::assertStringContainsString('href="/about/license"', $answer->body);
        self::assertStringContainsString('href="/fonts/OFL.txt?v=' . Version::CURRENT . '"', $answer->body, 'the license of the fonts is a file of the program');
        self::assertStringContainsString('href="' . AboutController::REPOSITORY . '"', $answer->body);
        self::assertStringContainsString('rel="noopener noreferrer"', $answer->body, 'a link to another site does not hand it the page');
        self::assertSame(1, substr_count($answer->body, 'aria-current="page"'), 'the version in the menu is the current page');
    }

    public function testTheFactsForAReportHaveNoPathsAndNoSecrets(): void
    {
        $body = $this->browser->get('/about')->body;

        self::assertSame(1, preg_match('~<pre id="facts" class="code">(.*?)</pre>~s', $body, $m));
        $facts = html_entity_decode($m[1] ?? '');
        self::assertStringContainsString('ThreadSafe ' . Version::CURRENT, $facts);
        self::assertStringContainsString('PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ')', $facts);
        self::assertStringContainsString('memory_limit ', $facts);
        self::assertStringContainsString('Website types: ', $facts);
        self::assertStringContainsString('generic', $facts);
        self::assertStringNotContainsString(dirname(__DIR__, 3), $facts, 'no path of this machine');
        self::assertStringNotContainsString($this->tempDirectory(), $facts);
        self::assertStringContainsString('data-copy="#facts"', $body, 'and a button to copy them');
    }

    public function testTheLicenseTextIsTheFileOfTheProgramAsPlainText(): void
    {
        $answer = $this->browser->get('/about/license');

        self::assertSame(200, $answer->status);
        self::assertStringStartsWith('text/plain', $answer->header('Content-Type') ?? '');
        self::assertSame('nosniff', $answer->header('X-Content-Type-Options'));
        self::assertStringContainsString('GNU GENERAL PUBLIC LICENSE', $answer->body);
        self::assertStringContainsString('Version 3, 29 June 2007', $answer->body);
    }

    public function testNothingOfItIsThereBeforeTheLogin(): void
    {
        $visitor = new AdminBrowser($this->browser->data);
        $visitor->now = $this->browser->now;

        foreach (['/about', '/about/license'] as $route) {
            $answer = $visitor->get($route);
            self::assertSame(303, $answer->status, $route);
            self::assertStringEndsWith('/login', $answer->header('Location') ?? '', $route);
        }
        $login = $visitor->get('/login')->body;
        // the addresses of the style sheet and the script carry the version (a new version is a new address for the browser's cache), so the
        // page does not hide it from someone who looks at them; what it does not do is to print it
        self::assertStringNotContainsString(Version::CURRENT, html_entity_decode(strip_tags($login)), 'the page before the login does not print which version it is');
        self::assertStringNotContainsString('class="version', $login);
        self::assertStringContainsString('<footer>ThreadSafe</footer>', $login);
    }
}
