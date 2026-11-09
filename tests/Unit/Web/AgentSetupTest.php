<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Profile\EncryptionSettings;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\AgentPairing;
use ThreadSafe\Web\AgentSetup;
use ThreadSafe\Web\BackupTarget;
use ThreadSafe\Web\BackupWorkspace;
use ThreadSafe\Web\Request;

/**
 * What a person on the host does to give the agent a profile and a pairing (docs/PHASE13.md 3.2): the profile of the agent is a copy of the profile of the
 * tool under the id of the central profile, only an encrypted one will do, and a new pairing makes a new secret.
 */
final class AgentSetupTest extends TestCase
{
    use TempDirectory;

    private const string URL = 'https://shop.example/wp-content/plugins/threadsafe-connector/threadsafe-backup.php';

    private string $site;

    private BackupWorkspace $workspace;

    protected function setUp(): void
    {
        $this->site = $this->tempDirectory() . '/site';
        mkdir($this->site . '/tools', 0777, true);
        file_put_contents($this->site . '/index.php', '<?php echo 1;');
        $this->workspace = BackupWorkspace::in($this->site . '/tools');
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function source(bool $encrypted = true): SiteProfile
    {
        $encryption = $encrypted ? EncryptionSettings::fromArray(['recipients' => [AgeKeys::generate()['public']]]) : null;

        return (new BackupTarget($this->site . '/tools'))->profile($this->site, 'generic', $this->workspace->storage(), null, ['/cache/'], 1048576, $encryption, 'a note');
    }

    public function testOnlyAnEncryptedProfileCanBeTheProfileOfTheAgent(): void
    {
        self::assertNull(AgentSetup::problem($this->source()));
        $problem = AgentSetup::problem($this->source(false));

        self::assertNotNull($problem);
        self::assertStringContainsString('encrypted backups only', $problem);
        self::assertStringContainsString('never holds it', $problem);
    }

    public function testThePairingMakesTheProfileUnderTheCentralIdAndTheSecretAndLeavesTheAgentOff(): void
    {
        $setup = new AgentSetup($this->workspace);

        $string = $setup->pair($this->source(), 'shop', self::URL);

        $decoded = AgentPairing::decode($string);
        self::assertSame(['url' => self::URL, 'profile' => 'shop'], ['url' => $decoded['url'], 'profile' => $decoded['profile']]);
        self::assertSame($decoded['secret'], $setup->config()->secret());
        self::assertFalse($setup->config()->isOn(), 'a person switches it on when the central side has the string');
        $profile = $this->workspace->profiles()->load('shop');
        self::assertSame('shop', $profile->id);
        self::assertSame($this->site, $profile->path);
        self::assertContains('/cache/', $profile->excludePaths);
        self::assertNotNull($profile->encryption, 'the keys came with the copy');
        self::assertSame(1048576, $profile->partSize);
    }

    public function testAProfileWithoutKeysIsRefusedAndNothingIsWritten(): void
    {
        $setup = new AgentSetup($this->workspace);

        try {
            $setup->pair($this->source(false), 'shop', self::URL);
            self::fail('a plain profile must be refused');
        } catch (ProfileException $e) {
            self::assertStringContainsString('encrypted', $e->getMessage());
        }
        self::assertFalse($setup->config()->isPaired());
        self::assertFalse($this->workspace->profiles()->exists('shop'));
    }

    public function testABadIdOrAddressIsRefusedBeforeAnythingIsWritten(): void
    {
        $setup = new AgentSetup($this->workspace);

        foreach ([['Not A Profile', self::URL], ['shop', 'shop.example']] as [$id, $url]) {
            try {
                $setup->pair($this->source(), $id, $url);
                self::fail("$id / $url must be refused");
            } catch (InvalidArgumentException) {
                // the id is checked by the settings, the address by the pairing string
            }
        }
        self::assertFalse($this->workspace->profiles()->exists('Not A Profile'));
    }

    public function testPairingAgainMakesANewSecretAndMovesTheProfileToTheNewId(): void
    {
        $setup = new AgentSetup($this->workspace);
        $setup->pair($this->source(), 'shop', self::URL);
        $first = $setup->config()->secret();
        $setup->config()->enable(true);

        $setup->pair($this->source(), 'other', self::URL);

        self::assertNotSame($first, $setup->config()->secret(), 'the old pairing stops working');
        self::assertSame('other', $setup->config()->profile());
        self::assertTrue($this->workspace->profiles()->exists('other'));
        self::assertFalse($this->workspace->profiles()->exists('shop'), 'the profile of the old pairing is gone');
        self::assertTrue($setup->config()->isOn(), 'the agent stays as switched as it was');
    }

    public function testSyncCopiesTheSettingsAgainWithoutANewSecret(): void
    {
        $setup = new AgentSetup($this->workspace);
        $setup->pair($this->source(), 'shop', self::URL);
        $secret = $setup->config()->secret();
        $changed = (new BackupTarget($this->site . '/tools'))->profile($this->site, 'generic', $this->workspace->storage(), null, ['/other/'], 1048576, EncryptionSettings::fromArray(['recipients' => [AgeKeys::generate()['public']]]));

        $setup->sync($changed);

        self::assertSame($secret, $setup->config()->secret());
        self::assertContains('/other/', $this->workspace->profiles()->load('shop')->excludePaths);
        $this->expectException(ProfileException::class);
        $setup->sync($this->source(false));
    }

    public function testSyncNeedsAPairing(): void
    {
        $this->expectException(ProfileException::class);
        $this->expectExceptionMessage('not paired');

        (new AgentSetup($this->workspace))->sync($this->source());
    }

    public function testUnpairingForgetsTheSecretAndTheProfileAndSwitchesOff(): void
    {
        $setup = new AgentSetup($this->workspace);
        $setup->pair($this->source(), 'shop', self::URL);
        $setup->config()->enable(true);

        $setup->unpair();

        self::assertFalse($setup->config()->isOn());
        self::assertFalse($setup->config()->isPaired());
        self::assertFalse($this->workspace->profiles()->exists('shop'));
    }

    public function testTheAddressOfTheToolComesFromTheRequest(): void
    {
        self::assertSame('https://shop.example/tools/threadsafe-backup.php', AgentSetup::addressOf(new Request(https: true, path: '/tools/threadsafe-backup.php'), 'shop.example'));
        self::assertSame('http://shop.example:8080/x.php', AgentSetup::addressOf(new Request(https: false, path: '/x.php'), 'shop.example:8080'));
        self::assertNull(AgentSetup::addressOf(new Request(https: true, path: '/x.php'), ''), 'no host name: the person gives the address');
        self::assertNull(AgentSetup::addressOf(new Request(https: true, path: ''), 'shop.example'));
    }
}
