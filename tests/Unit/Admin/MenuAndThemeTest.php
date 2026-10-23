<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Tests\Support\AdminBrowser;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The menu on the left (icon and name per entry, foldable) and the colour scheme (automatic, light, dark): what the server draws from the
 * two cookies that the script sets, and the files the style needs (the fonts that are part of the program).
 */
final class MenuAndThemeTest extends TestCase
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

    public function testTheMenuHasAnIconAndANameForEveryEntryAndMarksThePage(): void
    {
        $page = $this->browser->get('/profiles')->body;

        self::assertMatchesRegularExpression('~<aside class="side" aria-label="Menu" data-cookie-path="/">~', $page);
        self::assertStringContainsString('<nav id="side-nav" aria-label="Main">', $page);
        foreach (['Dashboard' => '/', 'Profiles' => '/profiles', 'System' => '/system'] as $name => $route) {
            self::assertMatchesRegularExpression('~<a href="' . preg_quote($route, '~') . '" title="' . $name . '"[^>]*><svg class="icon"[^>]*aria-hidden="true"[^>]*>.*?</svg><span class="side-label">' . $name . '</span></a>~', $page, $name);
        }
        self::assertSame(1, substr_count($page, 'aria-current="page"'));
        self::assertStringContainsString('<span aria-hidden="true">TS</span>', $page, 'TS stands for the logo until there is one');
        self::assertStringContainsString('Log out (admin)', $page);
        self::assertStringNotContainsString('<header class="bar">', $page, 'the bar on top is only for the pages before the login');
    }

    public function testTheFoldButtonSitsUnderTheLogoAndAboveTheEntries(): void
    {
        $page = $this->browser->get('/')->body;

        $mark = strpos($page, 'class="mark"');
        $toggle = strpos($page, 'data-nav-toggle');
        $nav = strpos($page, '<nav id="side-nav"');
        self::assertNotFalse($mark);
        self::assertTrue($mark < $toggle && $toggle < $nav, 'logo, then the button, then the entries');
        self::assertStringContainsString('<span class="side-label">Fold the menu</span>', $page, 'the button has a name of its own, shown while the menu is open');
    }

    public function testTheKeyboardCanSkipTheMenuAndTheLandmarksHaveNames(): void
    {
        $page = $this->browser->get('/')->body;

        self::assertMatchesRegularExpression('~<body><a class="skip" href="#content">Skip to the content</a>~', $page, 'the first thing to reach');
        self::assertStringContainsString('<main id="content" tabindex="-1">', $page, 'and what it leads to');
        self::assertStringContainsString('<aside class="side" aria-label="Menu"', $page);
        self::assertStringContainsString('<nav id="side-nav" aria-label="Main">', $page);
        self::assertSame(1, substr_count($page, '<h1>'), 'one h1 per page');

        $login = (new AdminBrowser($this->browser->data))->get('/login')->body;
        self::assertMatchesRegularExpression('~<body><a class="skip" href="#content">~', $login);
        self::assertStringContainsString('<main class="narrow" id="content" tabindex="-1">', $login);
    }

    public function testTheHeadingsOfTheDashboardDoNotSkipALevel(): void
    {
        $page = $this->browser->get('/')->body;

        self::assertStringContainsString('<article class="card ', $page, 'the fixture has a profile');
        self::assertMatchesRegularExpression('~<article class="card [^"]*"><header><div class="card-title"><h2>blog</h2>~', $page, 'h1, then h2 for each site');
        self::assertStringNotContainsString('<h3', $page);
    }

    public function testWithoutCookiesTheSchemeIsAutomaticAndTheMenuIsOpen(): void
    {
        $page = $this->browser->get('/')->body;

        self::assertStringContainsString('<html lang="en"><head>', $page, 'no data-theme, no data-nav');
        self::assertStringContainsString('data-theme-set="auto" aria-pressed="true"', $page);
        self::assertStringContainsString('data-theme-set="light" aria-pressed="false"', $page);
        self::assertStringContainsString('data-theme-set="dark" aria-pressed="false"', $page);
        self::assertStringContainsString('data-nav-toggle aria-expanded="true"', $page);
    }

    public function testTheCookieOfTheSchemeIsDrawnFromTheFirstByte(): void
    {
        foreach (['dark', 'light'] as $theme) {
            $this->browser->cookies = ['threadsafe_theme' => $theme];

            $page = $this->browser->get('/')->body;

            self::assertStringContainsString('<html lang="en" data-theme="' . $theme . '">', $page, $theme);
            self::assertStringContainsString('data-theme-set="' . $theme . '" aria-pressed="true"', $page);
            self::assertSame(1, substr_count($page, 'aria-pressed="true"'));
        }
    }

    public function testAForgedValueIsAutomatic(): void
    {
        $this->browser->cookies = ['threadsafe_theme' => '"><script>alert(1)</script>', 'threadsafe_nav' => 'sideways'];

        $page = $this->browser->get('/')->body;

        self::assertStringNotContainsString('<script>alert', $page);
        self::assertStringContainsString('<html lang="en"><head>', $page);
        self::assertStringContainsString('data-theme-set="auto" aria-pressed="true"', $page);
    }

    public function testTheFoldedMenuIsDrawnFolded(): void
    {
        $this->browser->cookies = ['threadsafe_nav' => 'collapsed'];

        $page = $this->browser->get('/system')->body;

        self::assertStringContainsString('<html lang="en" data-nav="collapsed">', $page);
        self::assertStringContainsString('data-nav-toggle aria-expanded="false"', $page);
        self::assertStringContainsString('title="Unfold the menu"', $page);
        self::assertStringContainsString('<span class="side-label">Unfold the menu</span>', $page);
        self::assertStringContainsString('<span class="side-label">Dashboard</span>', $page, 'the names stay in the page for assistive technology');
    }

    public function testThePagesBeforeTheLoginHaveTheMarkAndTheSwitchButNoMenu(): void
    {
        $login = new AdminBrowser($this->browser->data);
        $login->cookies = ['threadsafe_theme' => 'dark', 'threadsafe_nav' => 'collapsed'];

        $page = $login->get('/login')->body;

        self::assertStringContainsString('<html lang="en" data-theme="dark">', $page, 'the choice of the menu does not apply: there is no menu');
        self::assertStringContainsString('<header class="bar">', $page);
        self::assertStringContainsString('<span aria-hidden="true">TS</span>', $page);
        self::assertStringContainsString('data-theme-set="dark" aria-pressed="true"', $page);
        self::assertStringNotContainsString('<aside', $page);
    }

    public function testTheContentSecurityPolicyAllowsTheFontsOfTheProgramAndNothingElse(): void
    {
        $policy = (string) $this->browser->get('/')->header('Content-Security-Policy');

        self::assertStringContainsString("font-src 'self'", $policy);
        self::assertStringContainsString("default-src 'none'", $policy);
        self::assertStringNotContainsString('http', $policy);
    }

    public function testTheFontsAreInTheProgramAndTheStyleUsesThem(): void
    {
        $public = dirname(__DIR__, 3) . '/public';
        $css = (string) file_get_contents($public . '/app.css');

        self::assertSame(7, preg_match_all('~url\("(fonts/[a-z0-9-]+\.woff2)"\) format\("woff2"\)~', $css, $m), 'Plex Sans in four weights, Plex Mono in three');
        foreach ($m[1] as $file) {
            self::assertFileExists($public . '/' . $file);
            self::assertSame('wOF2', substr((string) file_get_contents($public . '/' . $file, false, null, 0, 4), 0, 4), "$file is a WOFF2 file");
        }
        self::assertFileExists($public . '/fonts/OFL.txt', 'the license of the fonts goes with them');
        self::assertStringNotContainsString('//fonts.', $css);
        self::assertStringNotContainsString('https://', $css, 'the style asks for nothing from another server');
    }

    public function testAMessageHasTheTintOfItsStatusAsBackground(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/app.css');

        foreach (['ok', 'warning', 'error'] as $status) {
            self::assertStringContainsString('.flash.' . $status . ' { border-left-color: var(--' . $status . '); background: color-mix(in srgb, var(--' . $status . ') 12%, var(--panel-2)); }', $css, $status);
        }
    }

    public function testTheScriptAndTheServerAgreeOnTheCookies(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/public/app.js');

        self::assertStringContainsString("'threadsafe_theme'", $script);
        self::assertStringContainsString("'threadsafe_nav'", $script);
        self::assertStringContainsString('samesite=lax', $script);
        self::assertStringContainsString("root.classList.add('js')", $script, 'what needs script is shown only when there is script');
    }
}
