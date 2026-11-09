<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Tests\Support\AdminBrowser;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\AgentPairing;

/**
 * The pages of a site that an agent backs up (docs/PHASE13.md 3.3, M2): the form with the pairing string, the card and the page of the profile that show the agent and
 * never its secret, and the edit that keeps or replaces the pairing. The address in the string is one that nothing answers, so that "test" has something to say.
 */
final class RemoteProfilePagesTest extends TestCase
{
    use TempDirectory;

    private const string URL = 'https://127.0.0.1:9/threadsafe-backup.php';

    private const string SECRET = 'aabbccddeeff00112233445566778899aabbccddeeff00112233445566778899';

    private string $base;

    private AdminBrowser $browser;

    protected function setUp(): void
    {
        $this->base = $this->tempDirectory();
        $this->browser = new AdminBrowser($this->base . '/data');
        $this->browser->install();
        $this->browser->login();
        $this->browser->get('/');
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function store(): ProfileStore
    {
        return new ProfileStore($this->base . '/data/profiles');
    }

    /**
     * @param array<string, string> $changes
     *
     * @return array<string, string>
     */
    private function fields(array $changes = []): array
    {
        return $changes + ['action' => 'save', 'id' => 'shop', 'name' => 'The shop', 'pairing' => AgentPairing::encode(self::URL, self::SECRET, 'shop'), 'storage' => $this->base . '/storage/shop',
            'note' => '', 'skip_test' => '1', 'schedule_every' => '', 'keep' => '', 'max_age_days' => '', 'max_size' => ''];
    }

    private function create(): void
    {
        $response = $this->browser->post('/remote-profiles', $this->fields());
        self::assertSame(303, $response->status, $response->body);
    }

    public function testTheListOffersASiteWithAnAgentNextToANewProfile(): void
    {
        $empty = $this->browser->get('/profiles');
        self::assertStringContainsString('href="/new-remote-profile"', $empty->body);
        self::assertStringContainsString('New site with an agent', $empty->body);

        $this->create();
        $page = $this->browser->get('/profiles');
        self::assertStringContainsString('<a class="button secondary" href="/new-remote-profile">New site with an agent</a>', $page->body);
    }

    public function testTheFormAsksForThePairingStringAndNothingThatIsSetOnTheSite(): void
    {
        $page = $this->browser->get('/new-remote-profile');

        self::assertSame(200, $page->status);
        self::assertStringContainsString('name="pairing"', $page->body);
        self::assertStringContainsString('name="plain_http"', $page->body);
        self::assertStringContainsString('name="storage"', $page->body);
        self::assertStringContainsString('name="schedule_every"', $page->body);
        foreach (['name="path"', 'name="db_mode"', 'name="exclude"', 'name="recipients"', 'name="compression"'] as $local) {
            self::assertStringNotContainsString($local, $page->body, "$local is set on the site");
        }
        self::assertStringContainsString('set on the site', $page->body);
    }

    public function testASiteWithAnAgentIsCreatedFromThePairingString(): void
    {
        $this->create();

        $profile = $this->store()->load('shop');
        self::assertTrue($profile->isRemote());
        self::assertSame(self::URL, $profile->remote?->url);
        self::assertSame(self::SECRET, $profile->remote?->secret);
        self::assertSame('The shop', $profile->name);
        self::assertSame('remote', $profile->type);
        self::assertSame($this->base . '/storage/shop', $profile->storage);
    }

    public function testTheIdMayBeLeftEmptyAndIsTheOneInTheString(): void
    {
        $response = $this->browser->post('/remote-profiles', $this->fields(['id' => '', 'name' => '']));

        self::assertSame(303, $response->status, $response->body);
        self::assertTrue($this->store()->exists('shop'));
        self::assertSame('shop', $this->store()->load('shop')->name);
    }

    public function testTheCardAndThePageSayWhereTheAgentIsAndNeverShowTheSecret(): void
    {
        $this->create();

        $list = $this->browser->get('/profiles')->body;
        $page = $this->browser->get('/profiles/shop')->body;
        $settings = $this->browser->get('/profiles/shop?tab=settings')->body;
        $edit = $this->browser->get('/profiles/shop/edit')->body;

        self::assertStringContainsString('<dt>Agent</dt><dd><code class="path">' . str_replace('/', '/<wbr>', self::URL) . '</code></dd>', $list);
        self::assertStringNotContainsString('<dt>Site</dt>', $list);
        self::assertStringContainsString('<dt>Agent</dt>', $page);
        self::assertStringContainsString('what is backed up, the database, what is left out and the keys are set there', $settings);
        self::assertStringNotContainsString('/profiles/shop/keys', $settings, 'the keys of a remote site are set on the site');
        self::assertStringContainsString(implode('-', str_split((new \ThreadSafe\Profile\RemoteSettings(self::URL, self::SECRET))->fingerprint(), 4)), $edit);
        foreach ([$list, $page, $settings, $edit] as $body) {
            self::assertStringNotContainsString(self::SECRET, $body);
            self::assertStringNotContainsString('ts1:', $body);
        }
    }

    public function testWithoutTheConsentToSkipTheTestAnAgentThatDoesNotAnswerKeepsTheProfileOut(): void
    {
        $response = $this->browser->post('/remote-profiles', $this->fields(['skip_test' => '']));

        self::assertSame(400, $response->status);
        self::assertStringContainsString('could not be reached', $response->body);
        self::assertStringContainsString('was not stored', $response->body);
        self::assertFalse($this->store()->exists('shop'));
        self::assertStringNotContainsString(self::SECRET, $response->body);
    }

    public function testOnlyTestShowsTheReportAndStoresNothing(): void
    {
        $response = $this->browser->post('/remote-profiles', $this->fields(['action' => 'test', 'skip_test' => '']));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('could not be reached', $response->body);
        self::assertFalse($this->store()->exists('shop'));
    }

    public function testWhatIsWrongWithTheFormIsToldAndNothingIsStored(): void
    {
        $none = $this->browser->post('/remote-profiles', $this->fields(['pairing' => '']));
        self::assertSame(400, $none->status);
        self::assertStringContainsString('Paste the pairing string', $none->body);

        $other = $this->browser->post('/remote-profiles', $this->fields(['id' => 'blog']));
        self::assertSame(400, $other->status);
        self::assertStringContainsString('is for the profile &quot;shop&quot;, and this profile is &quot;blog&quot;', $other->body);

        $damaged = $this->browser->post('/remote-profiles', $this->fields(['pairing' => 'ts1:not-a-pairing-string']));
        self::assertSame(400, $damaged->status);
        self::assertStringContainsString('damaged', $damaged->body);

        $bad = $this->browser->post('/remote-profiles', $this->fields(['keep' => 'many']));
        self::assertSame(400, $bad->status);
        self::assertStringContainsString('whole number', $bad->body);

        self::assertSame([], $this->store()->ids());
        foreach ([$none, $other, $damaged, $bad] as $page) {
            self::assertStringNotContainsString(self::SECRET, $page->body, 'a page that says what is wrong does not repeat the secret');
        }
    }

    public function testPlainHttpNeedsTheConsent(): void
    {
        $plain = AgentPairing::encode('http://127.0.0.1:9/threadsafe-backup.php', self::SECRET, 'shop');

        $refused = $this->browser->post('/remote-profiles', $this->fields(['pairing' => $plain]));
        self::assertSame(400, $refused->status);
        self::assertStringContainsString('plain http', $refused->body);

        $accepted = $this->browser->post('/remote-profiles', $this->fields(['pairing' => $plain, 'plain_http' => '1']));
        self::assertSame(303, $accepted->status, $accepted->body);
        self::assertTrue($this->store()->load('shop')->remote?->plainHttp);
    }

    public function testEditKeepsThePairingUnlessANewStringIsPasted(): void
    {
        $this->create();

        $kept = $this->browser->post('/profiles/shop', $this->fields(['pairing' => '', 'name' => 'Renamed', 'note' => 'now with a note']));
        self::assertSame(303, $kept->status, $kept->body);
        $profile = $this->store()->load('shop');
        self::assertSame('Renamed', $profile->name);
        self::assertSame('now with a note', $profile->note);
        self::assertSame(self::SECRET, $profile->remote?->secret, 'the secret stays');

        $newSecret = str_repeat('12', 32);
        $again = $this->browser->post('/profiles/shop', $this->fields(['pairing' => AgentPairing::encode(self::URL, $newSecret, 'shop')]));
        self::assertSame(303, $again->status, $again->body);
        self::assertSame($newSecret, $this->store()->load('shop')->remote?->secret);
        self::assertTrue($this->store()->load('shop')->isRemote());
    }

    public function testTheScheduleAndTheRetentionAreSetLikeForAnyProfile(): void
    {
        $response = $this->browser->post('/remote-profiles', $this->fields(['schedule_every' => 'day', 'schedule_time' => '03:30', 'keep' => '7', 'max_age_days' => '30']));

        self::assertSame(303, $response->status, $response->body);
        $profile = $this->store()->load('shop');
        self::assertSame('Every day at 03:30', $profile->schedule?->describe());
        self::assertSame(7, $profile->retention?->keep);
        self::assertSame(30, $profile->retention?->maxAgeDays);
    }

    public function testRemovingTheProfileSaysThatTheSiteAndItsAgentAreNotTouched(): void
    {
        $this->create();

        $page = $this->browser->get('/profiles/shop/delete');

        self::assertSame(200, $page->status);
        self::assertStringContainsString('The site and its agent is not touched', $page->body);
    }
}
