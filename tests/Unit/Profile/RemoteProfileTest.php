<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Profile;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\RemoteSettings;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The block `remote` of a profile (docs/PHASE13.md 3.3): a site on another machine, made by an agent and fetched by this one.
 */
final class RemoteProfileTest extends TestCase
{
    use TempDirectory;

    private const string URL = 'https://shop.example/wp-content/plugins/threadsafe-connector/threadsafe-backup.php';

    private const string SECRET = 'aabbccddeeff00112233445566778899aabbccddeeff00112233445566778899';

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @param array<string, mixed> $more
     *
     * @return array<string, mixed>
     */
    private static function data(array $more = []): array
    {
        return ['id' => 'shop', 'storage' => '/var/backups/shop', 'remote' => ['url' => self::URL, 'secret' => self::SECRET], ...$more];
    }

    public function testARemoteProfileNeedsNeitherAPathNorATypeNorADatabase(): void
    {
        $profile = SiteProfile::fromArray(self::data());

        self::assertTrue($profile->isRemote());
        self::assertSame('', $profile->path);
        self::assertSame(SiteProfile::REMOTE_TYPE, $profile->type);
        self::assertNull($profile->database);
        self::assertSame(self::URL, $profile->remote?->url);
        self::assertSame(self::SECRET, $profile->remote?->secret);
        self::assertFalse($profile->remote?->plainHttp);
    }

    public function testTheTypeThatTheAgentReportedIsKept(): void
    {
        $profile = SiteProfile::fromArray(self::data(['type' => 'wordpress']));

        self::assertSame('wordpress', $profile->type);
    }

    public function testAProfileWithoutARemoteBlockStillNeedsItsPathAndType(): void
    {
        foreach ([['id' => 'shop', 'storage' => '/s'], ['id' => 'shop', 'type' => 'generic', 'storage' => '/s']] as $data) {
            try {
                SiteProfile::fromArray($data);
                self::fail('a local profile needs a type and a path');
            } catch (ProfileException $e) {
                self::assertMatchesRegularExpression('/"(type|path)"/', $e->getMessage());
            }
        }
        self::assertFalse(SiteProfile::fromArray(['id' => 'shop', 'type' => 'generic', 'path' => '/var/www/shop', 'storage' => '/s'])->isRemote());
    }

    public function testTheSecretIsInTheStoredDataAndMaskedInWhatIsShown(): void
    {
        $profile = SiteProfile::fromArray(self::data());

        self::assertSame(self::SECRET, $profile->toArray()['remote']['secret']);
        self::assertSame('********', $profile->toArray(false)['remote']['secret']);
        self::assertStringNotContainsString(self::SECRET, json_encode($profile->toArray(false), JSON_THROW_ON_ERROR));
        self::assertSame(self::SECRET, $profile->with(['note' => 'changed'])->remote?->secret, 'a copy with a change keeps the secret');
    }

    public function testAProfileThatIsNotRemoteHasNoRemoteKeyWhenItIsWritten(): void
    {
        $profile = SiteProfile::fromArray(['id' => 'shop', 'type' => 'generic', 'path' => '/var/www/shop', 'storage' => '/s']);

        self::assertArrayNotHasKey('remote', $profile->toArray());
    }

    public function testItIsKeptInTheStoreAndReadAgainAndTheFileIsPrivate(): void
    {
        $directory = $this->tempDirectory();
        $store = new ProfileStore($directory);
        $store->save(SiteProfile::fromArray(self::data(['note' => 'on another host'])));

        $loaded = $store->load('shop');

        self::assertTrue($loaded->isRemote());
        self::assertSame(self::SECRET, $loaded->remote?->secret);
        self::assertSame('on another host', $loaded->note);
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0600, fileperms($directory . '/shop.json') & 0777, 'the file holds the secret of the pairing, like a database password');
        }
    }

    public function testAnAgentThatIsReachedOverPlainHttpNeedsTheConsentOfThePerson(): void
    {
        $plain = ['url' => 'http://shop.example/threadsafe-backup.php', 'secret' => self::SECRET];

        try {
            SiteProfile::fromArray(self::data(['remote' => $plain]));
            self::fail('plain http must be refused without the consent');
        } catch (ProfileException $e) {
            self::assertStringContainsString('plain http', $e->getMessage());
        }
        $accepted = SiteProfile::fromArray(self::data(['remote' => [...$plain, 'plainHttp' => true]]));
        self::assertTrue($accepted->remote?->plainHttp);
        self::assertFalse($accepted->remote->isHttps());
    }

    public function testARemoteProfileHasNoDatabaseOfItsOwn(): void
    {
        $this->expectException(ProfileException::class);
        $this->expectExceptionMessage('no "database" of its own');

        SiteProfile::fromArray(self::data(['database' => ['name' => 'shop', 'user' => 'shop']]));
    }

    /**
     * @return array<string, array{array<array-key, mixed>, string}> the remote block, a word of the message
     */
    public static function badBlocks(): array
    {
        return [
            'a list' => [['a', 'b'], 'must be null or an object'],
            'an unknown field' => [['url' => self::URL, 'secret' => self::SECRET, 'password' => 'x'], 'Unknown field "password"'],
            'no address' => [['secret' => self::SECRET], 'remote.url'],
            'an address that is none' => [['url' => 'shop.example', 'secret' => self::SECRET], 'not an http or https address'],
            'an address with a password' => [['url' => 'https://user:pass@shop.example/x.php', 'secret' => self::SECRET], 'user name or a password'],
            'an address with a query' => [['url' => 'https://shop.example/x.php?api=status', 'secret' => self::SECRET], 'query or a fragment'],
            'an address with a space' => [['url' => 'https://shop.example/a b.php', 'secret' => self::SECRET], 'space'],
            'no secret' => [['url' => self::URL], 'remote.secret'],
            'a secret that is too short' => [['url' => self::URL, 'secret' => 'abcd'], 'remote.secret'],
            'a secret in capitals' => [['url' => self::URL, 'secret' => strtoupper(self::SECRET)], 'remote.secret'],
            'plainHttp that is no boolean' => [['url' => self::URL, 'secret' => self::SECRET, 'plainHttp' => 'yes'], 'remote.plainHttp'],
        ];
    }

    /**
     * @param array<array-key, mixed> $block
     */
    #[DataProvider('badBlocks')]
    public function testABadBlockIsRefusedWithAReason(array $block, string $word): void
    {
        $this->expectException(ProfileException::class);
        $this->expectExceptionMessage($word);

        SiteProfile::fromArray(self::data(['remote' => $block]));
    }

    public function testTheFingerprintIsTheOneTheAgentShows(): void
    {
        $settings = new RemoteSettings(self::URL, self::SECRET);

        self::assertSame(substr(hash('sha256', 'threadsafe-agent-fingerprint ' . self::SECRET), 0, 16), $settings->fingerprint());
        self::assertTrue($settings->isHttps());
    }
}
