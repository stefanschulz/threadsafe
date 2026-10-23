<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Tests\Support\AdminBrowser;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The filter above the tiles of the dashboard: by website type, in the address (so it works without script); the tile for a new profile
 * is there in every case.
 */
final class DashboardFilterTest extends TestCase
{
    use TempDirectory;

    private AdminBrowser $browser;

    protected function setUp(): void
    {
        $fixture = new BackupFixture($this->tempDirectory());
        // the fixture has "blog" (generic); two more of another type
        foreach (['shop', 'store'] as $id) {
            $fixture->profiles->save(SiteProfile::fromArray(['id' => $id, 'type' => 'prestashop9', 'path' => $fixture->site, 'storage' => $fixture->storageDirectory . '-' . $id]));
        }
        $this->browser = new AdminBrowser($fixture->data);
        $this->browser->install();
        $this->browser->login();
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /** @return list<string> the ids of the profiles that have a card */
    private function cards(string $body): array
    {
        preg_match_all('~<article class="card [^"]*"><header><div class="card-title"><h2>[^<]*</h2><a class="icon-button" href="/profiles/([a-z0-9_-]+)/edit"~', $body, $m);

        return $m[1];
    }

    public function testTheFilterOffersAllAndEveryTypeThatTheProfilesHaveWithTheirNumbers(): void
    {
        $page = $this->browser->get('/')->body;

        self::assertStringContainsString('<nav class="filter" aria-label="Filter the sites by website type">', $page);
        self::assertStringContainsString('<a href="/" aria-current="true">All <span class="count">3</span></a>', $page, 'all is chosen without a choice');
        self::assertMatchesRegularExpression('~<a href="/\?type=generic">[^<]+ <span class="count">1</span></a>~', $page);
        self::assertMatchesRegularExpression('~<a href="/\?type=prestashop9">[^<]+ <span class="count">2</span></a>~', $page);
        self::assertSame(['blog', 'shop', 'store'], $this->sorted($this->cards($page)));
        self::assertSame(1, substr_count($page, 'aria-current="true"'));
    }

    public function testAChosenTypeShowsOnlyItsSitesAndTheTileStays(): void
    {
        $page = $this->browser->send('GET', '/?type=prestashop9')->body;

        self::assertSame(['shop', 'store'], $this->sorted($this->cards($page)));
        self::assertMatchesRegularExpression('~<a href="/\?type=prestashop9" aria-current="true">~', $page, 'the chosen one is marked');
        self::assertStringContainsString('<a href="/">All', $page, 'and all is a way back');
        self::assertSame(1, substr_count($page, 'aria-current="true"'));
        self::assertStringContainsString('<a class="card new" href="/new-profile">', $page, 'the tile is always there');

        $other = $this->browser->send('GET', '/?type=generic')->body;
        self::assertSame(['blog'], $this->cards($other));
        self::assertStringContainsString('<a class="card new" href="/new-profile">', $other);
    }

    public function testATypeThatNoProfileHasIsTakenForAll(): void
    {
        foreach (['wordpress', '../x', '', 'prestashop9&x=1'] as $wanted) {
            $page = $this->browser->send('GET', '/?type=' . rawurlencode($wanted))->body;

            self::assertCount(3, $this->cards($page), $wanted);
            self::assertStringContainsString('<a href="/" aria-current="true">All', $page, $wanted);
            self::assertStringNotContainsString('x=1', $page);
        }
    }

    public function testWithoutPrettyAddressesTheFilterLinksStayValid(): void
    {
        $fixture = new BackupFixture($this->tempDirectory());
        \ThreadSafe\Admin\Config::defaults()->with('web.enabled', true)->with('web.prettyUrls', false)->save($fixture->data . '/config.json');
        $browser = new AdminBrowser($fixture->data);
        $browser->install();
        \ThreadSafe\Admin\Config::load($fixture->data . '/config.json')->with('web.prettyUrls', false)->save($fixture->data . '/config.json');
        $browser->login();

        $page = $browser->get('/')->body;

        self::assertStringContainsString('<a href="/index.php?type=generic">', $page, 'the dashboard is index.php itself; the query is added with ?');
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}