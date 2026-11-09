<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Cli\Application;
use ThreadSafe\Cli\BufferedOutput;
use ThreadSafe\Cli\ProfileCommand;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\RemoteSettings;
use ThreadSafe\Remote\AgentClient;
use ThreadSafe\Remote\AgentResponse;
use ThreadSafe\Tests\Support\AgentHost;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\AgentPairing;

/**
 * `profile add|edit|show|list|test` for a site that an agent backs up (docs/PHASE13.md 3.3): the pairing string comes from a file, never from the command line; what is set on
 * the site is refused here; the test asks the agent.
 */
final class RemoteProfileCommandTest extends TestCase
{
    use TempDirectory;

    private AgentHost $host;

    private string $data;

    private string $storage;

    private string $pairingFile;

    /** How far the clock of this machine is from the one of the site, in seconds. */
    private int $skew = 0;

    protected function setUp(): void
    {
        $base = $this->tempDirectory();
        $this->host = new AgentHost($base);
        $this->data = $base . '/central/data';
        $this->storage = $base . '/central/storage';
        mkdir($this->data . '/profiles', 0777, true);
        $this->pairingFile = $base . '/pairing.txt';
        file_put_contents($this->pairingFile, AgentPairing::encode(AgentHost::URL, $this->host->secret, 'shop') . "\n");
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function cli(array $arguments): array
    {
        $command = new ProfileCommand($this->data, PluginRegistry::load(), fn (RemoteSettings $remote): AgentClient => new AgentClient($remote, $this->host->transport, fn (): int => $this->host->now + $this->skew));
        $output = new BufferedOutput();
        $code = (new Application([$command]))->run(['threadsafe', 'profile', ...$arguments], $output);

        return [$code, $output->stdout(), $output->stderr()];
    }

    /** @return list<string> */
    private function add(string ...$more): array
    {
        return array_values(['add', 'shop', '--pairing-file=' . $this->pairingFile, '--storage=' . $this->storage, ...$more]);
    }

    public function testASiteWithAnAgentIsAddedFromTheFileOfThePairingString(): void
    {
        [$code, $out, $err] = $this->cli($this->add('--name=The shop', '--note=on another host'));

        self::assertSame(0, $code, $err);
        self::assertStringContainsString('Profile "shop" added.', $out);
        $profile = (new ProfileStore($this->data . '/profiles'))->load('shop');
        self::assertTrue($profile->isRemote());
        self::assertSame(AgentHost::URL, $profile->remote?->url);
        self::assertSame($this->host->secret, $profile->remote?->secret);
        self::assertSame('The shop', $profile->name);
        self::assertSame('remote', $profile->type);
        self::assertSame('', $profile->path);
    }

    public function testTheTestOfAddAskedTheAgentAndShowedWhatItSaid(): void
    {
        $this->cli($this->add());
        [, $out] = $this->cli(['test', 'shop']);

        self::assertStringContainsString('answers: ThreadSafe 1.2.3', $out);
        self::assertStringContainsString('the profile "shop" is ready on the site', $out);
    }

    public function testNeitherTheSecretNorTheStringIsEverPrinted(): void
    {
        [, $added] = $this->cli($this->add());
        [, $shown] = $this->cli(['show', 'shop']);
        [, $json] = $this->cli(['show', 'shop', '--json']);
        [, $listed] = $this->cli(['list']);
        [, $tested] = $this->cli(['test', 'shop']);

        foreach ([$added, $shown, $json, $listed, $tested] as $text) {
            self::assertStringNotContainsString($this->host->secret, $text);
            self::assertStringNotContainsString('ts1:', $text);
        }
        self::assertStringContainsString('fingerprint of the secret ' . implode('-', str_split((new RemoteSettings(AgentHost::URL, $this->host->secret))->fingerprint(), 4)), $shown);
        self::assertStringContainsString('"secret": "********"', $json);
    }

    public function testShowAndListSayThatTheSiteIsElsewhere(): void
    {
        $this->cli($this->add());

        [, $shown] = $this->cli(['show', 'shop']);
        [, $listed] = $this->cli(['list']);

        self::assertStringContainsString('on another machine; agent at ' . AgentHost::URL, $shown);
        self::assertStringContainsString('what is backed up, the database, what is left out and the keys are set there', $shown);
        self::assertStringContainsString('agent at ' . AgentHost::URL, $listed);
    }

    public function testThePairingStringIsOnlyReadFromAFileAndMustBeForTheSameId(): void
    {
        [$code, , $err] = $this->cli(['add', 'blog', '--pairing-file=' . $this->pairingFile, '--storage=' . $this->storage]);
        self::assertSame(1, $code);
        self::assertStringContainsString('is for the profile "shop", and this profile is "blog"', $err);

        [$code, , $err] = $this->cli($this->add('--pairing-file=' . $this->pairingFile));
        self::assertSame(1, $code, 'given twice');
        self::assertStringContainsString('only once', $err);

        [$code, , $err] = $this->cli(['add', 'shop', '--pairing-file=' . $this->data . '/missing.txt', '--storage=' . $this->storage]);
        self::assertSame(1, $code);
        self::assertStringContainsString('Cannot read', $err);

        file_put_contents($this->pairingFile, 'ts1:not-a-pairing-string');
        [$code, , $err] = $this->cli($this->add());
        self::assertSame(1, $code);
        self::assertStringContainsString('damaged', $err);
        self::assertFalse((new ProfileStore($this->data . '/profiles'))->exists('shop'));
    }

    public function testWhatIsSetOnTheSiteIsRefusedHereWithAReason(): void
    {
        foreach (['--path=/var/www', '--recipient=age1xyz', '--exclude=/cache/', '--db-name=shop', '--part-size=100M', '--no-encryption'] as $option) {
            [$code, , $err] = $this->cli($this->add($option));
            self::assertSame(1, $code, $option);
            self::assertStringContainsString('does not apply to a site that an agent backs up', $err, $option);
        }
        [$code, , $err] = $this->cli(['add', 'shop', '--pairing-file=' . $this->pairingFile]);
        self::assertSame(1, $code);
        self::assertStringContainsString('needs --storage', $err);
    }

    public function testAnAgentThatCannotBeReachedKeepsTheProfileOutUnlessTheTestIsSkipped(): void
    {
        $this->host->config->enable(false);

        [$code, $out, $err] = $this->cli($this->add());

        self::assertSame(2, $code);
        self::assertStringContainsString('The agent does not answer', $out);
        self::assertStringContainsString('was not stored', $err);
        self::assertFalse((new ProfileStore($this->data . '/profiles'))->exists('shop'));

        [$code] = $this->cli($this->add('--no-test'));
        self::assertSame(0, $code);
        self::assertTrue((new ProfileStore($this->data . '/profiles'))->exists('shop'));
    }

    public function testTheTestReportsWhatIsWrongWithTheAgentAndWhatToDo(): void
    {
        $this->cli($this->add('--no-test'));

        // the clock of this machine is far off from the site's: the agent refuses the signed request
        $this->skew = -500;
        [$code, $out] = $this->cli(['test', 'shop']);
        $this->skew = 0;
        self::assertSame(2, $code);
        self::assertStringContainsString('The clocks of the two sides differ', $out);
        self::assertStringContainsString('correct the clock', $out);

        // the clock that the site reports is far off from this machine's (the site's own time is wrong): every request would be refused
        $this->host->transport->intercept = static fn (string $action): ?AgentResponse => $action === 'status' ? new AgentResponse(200, body: '{"api":1,"profile":{"id":"shop","ready":true},"time":' . (time() - 500) . '}') : null;
        [$code, $out] = $this->cli(['test', 'shop']);
        self::assertSame(2, $code);
        self::assertStringContainsString('every request would be refused', $out);

        // another API
        $this->host->transport->intercept = static fn (string $action): ?AgentResponse => $action === 'status' ? new AgentResponse(200, body: '{"api":9,"profile":{"id":"shop","ready":true},"time":' . time() . '}') : null;
        [, $out] = $this->cli(['test', 'shop']);
        self::assertStringContainsString('speaks version 9 of its API', $out);

        // a profile that is not ready, and an id that is not the one of the pairing
        $this->host->transport->intercept = static fn (string $action): ?AgentResponse => $action === 'status' ? new AgentResponse(200, body: '{"api":1,"profile":{"id":"shop","ready":false},"time":' . time() . '}') : null;
        [, $out] = $this->cli(['test', 'shop']);
        self::assertStringContainsString('no backup profile ready', $out);
        $this->host->transport->intercept = static fn (string $action): ?AgentResponse => $action === 'status' ? new AgentResponse(200, body: '{"api":1,"profile":{"id":"other","ready":true},"time":' . time() . '}') : null;
        [, $out] = $this->cli(['test', 'shop']);
        self::assertStringContainsString('under the profile id "other"', $out);
    }

    public function testASmallClockDifferenceIsAWarningAndAGoodOneIsOk(): void
    {
        $this->cli($this->add('--no-test'));
        $this->host->transport->intercept = static fn (string $action): ?AgentResponse => $action === 'status' ? new AgentResponse(200, body: '{"api":1,"profile":{"id":"shop","ready":true},"time":' . (time() + 80) . '}') : null;

        [$code, $out] = $this->cli(['test', 'shop']);

        self::assertSame(0, $code, 'a warning does not stop a backup');
        self::assertStringContainsString('WARNING Clock', $out);
        [, $good] = $this->cli(['test', 'shop', '--json']);
        self::assertStringContainsString('"subject": "Clock"', $good);
    }

    public function testEditChangesTheNoteAndTheSecretAndKeepsTheRest(): void
    {
        $this->cli($this->add());
        $newSecret = $this->host->config->pair('shop');
        file_put_contents($this->pairingFile, AgentPairing::encode(AgentHost::URL, $newSecret, 'shop'));

        [$code, $out, $err] = $this->cli(['edit', 'shop', '--pairing-file=' . $this->pairingFile, '--note=paired again']);

        self::assertSame(0, $code, $err);
        $profile = (new ProfileStore($this->data . '/profiles'))->load('shop');
        self::assertSame($newSecret, $profile->remote?->secret);
        self::assertSame('paired again', $profile->note);
        self::assertSame($this->storage, $profile->storage);
        self::assertStringNotContainsString($newSecret, $out);
    }

    public function testPlainHttpNeedsTheConsentAndEditCanGiveItAndTakeItBack(): void
    {
        $plain = 'http://shop.example/threadsafe-backup.php';
        file_put_contents($this->pairingFile, AgentPairing::encode($plain, $this->host->secret, 'shop'));

        [$code, , $err] = $this->cli($this->add('--no-test'));
        self::assertSame(1, $code);
        self::assertStringContainsString('plain http', $err);

        [$code] = $this->cli($this->add('--no-test', '--plain-http'));
        self::assertSame(0, $code);
        self::assertTrue((new ProfileStore($this->data . '/profiles'))->load('shop')->remote?->plainHttp);

        [$code, , $err] = $this->cli(['edit', 'shop', '--no-plain-http', '--no-test']);
        self::assertSame(1, $code, 'an http address cannot be without the consent');
        self::assertStringContainsString('plain http', $err);
    }

    public function testALocalProfileHasNoPairingAndARemoteOneCannotChangeWhatIsSetOnTheSite(): void
    {
        [$code, , $err] = $this->cli(['add', 'blog', '--type=generic', '--path=' . $this->data, '--storage=' . $this->storage . '/blog', '--plain-http']);
        self::assertSame(1, $code);
        self::assertStringContainsString('only makes sense for a site with an agent', $err);

        $this->cli($this->add());
        [$code, , $err] = $this->cli(['edit', 'shop', '--exclude=/cache/']);
        self::assertSame(1, $code);
        self::assertStringContainsString('does not apply to a site that an agent backs up', $err);
    }

    public function testASiteWithAnAgentCanBeScheduledLikeAnyOther(): void
    {
        $this->cli($this->add());

        [$code, , $err] = $this->cli(['edit', 'shop', '--schedule=daily at 03:30', '--keep=7', '--no-test']);

        self::assertSame(0, $code, $err);
        $profile = (new ProfileStore($this->data . '/profiles'))->load('shop');
        self::assertSame('daily at 03:30', $profile->schedule?->text());
        self::assertSame(7, $profile->retention?->keep);
        self::assertTrue($profile->isRemote(), 'still a remote profile');
    }
}
