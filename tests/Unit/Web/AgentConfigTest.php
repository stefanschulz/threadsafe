<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\AgentConfig;
use ThreadSafe\Web\AgentPairing;

/**
 * The settings of the agent: off until paired and switched on, written only by a person on the host, private, forgiving of a damaged file (which
 * means "off"), and the list of addresses that may ask.
 */
final class AgentConfigTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function config(?string $directory = null): AgentConfig
    {
        return AgentConfig::in($directory ?? $this->tempDirectory());
    }

    public function testWithoutAFileTheAgentIsOffAndUnpaired(): void
    {
        $config = $this->config();

        self::assertFalse($config->isOn());
        self::assertFalse($config->isPaired());
        self::assertSame(1, $config->keepOnHost());
        self::assertFalse($config->plainAllowed());
        self::assertSame([], $config->allow());
        self::assertTrue($config->allows('203.0.113.9'), 'no list: every address may ask');
    }

    public function testPairingMakesASecretAndTheAgentStaysOffUntilItIsSwitchedOn(): void
    {
        $directory = $this->tempDirectory();
        $config = $this->config($directory);

        $secret = $config->pair('shop', 1_800_000_000);

        self::assertMatchesRegularExpression(AgentPairing::SECRET_PATTERN, $secret);
        self::assertTrue($config->isPaired());
        self::assertFalse($config->isOn(), 'paired is not on: a person switches it on when the central side has the secret');
        self::assertSame('shop', $config->profile());
        self::assertSame(1_800_000_000, $config->pairedAt());

        $config->enable(true);
        self::assertTrue($config->isOn());
        $again = $this->config($directory);
        self::assertTrue($again->isOn(), 'what was written is read again');
        self::assertSame($secret, $again->secret());
        self::assertSame('shop', $again->profile());
    }

    public function testPairingAgainReplacesTheSecret(): void
    {
        $config = $this->config();
        $first = $config->pair('shop');

        self::assertNotSame($first, $config->pair('shop'));
    }

    public function testAProfileIdThatIsNotOneIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->config()->pair('Not A Profile');
    }

    public function testUnpairingSwitchesTheAgentOffAndForgetsTheSecret(): void
    {
        $config = $this->config();
        $config->pair('shop');
        $config->enable(true);

        $config->unpair();

        self::assertFalse($config->isOn());
        self::assertFalse($config->isPaired());
        self::assertSame('', $config->secret());
    }

    public function testTheFileIsPrivate(): void
    {
        $directory = $this->tempDirectory();
        $this->config($directory)->pair('shop');

        self::assertFileExists($directory . '/agent.json');
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0600, fileperms($directory . '/agent.json') & 0777);
        }
        self::assertSame([], glob($directory . '/*.tmp'), 'no temporary file is left');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function damagedFiles(): array
    {
        $secret = str_repeat('ab', 32);

        return [
            'not json' => ['{{{'],
            'a list instead of an object' => ['[1,2,3]'],
            'switched on but no secret' => ['{"enabled":true,"profile":"shop"}'],
            'switched on but a secret that is no secret' => ['{"enabled":true,"secret":"abc","profile":"shop"}'],
            'switched on but no profile' => ['{"enabled":true,"secret":"' . $secret . '"}'],
            'switched on with a profile id that is not one' => ['{"enabled":true,"secret":"' . $secret . '","profile":"A B"}'],
        ];
    }

    #[DataProvider('damagedFiles')]
    public function testADamagedFileMeansTheAgentIsOff(string $content): void
    {
        $directory = $this->tempDirectory();
        file_put_contents($directory . '/agent.json', $content);

        self::assertFalse($this->config($directory)->isOn());
    }

    public function testBadValuesInAGoodFileAreReplacedByTheDefaults(): void
    {
        $directory = $this->tempDirectory();
        file_put_contents($directory . '/agent.json', json_encode(['enabled' => true, 'secret' => str_repeat('ab', 32), 'profile' => 'shop', 'allow' => ['203.0.113.7', 'not an address', 7], 'keepOnHost' => 999, 'plain' => 'yes']));
        $config = $this->config($directory);

        self::assertTrue($config->isOn());
        self::assertSame(['203.0.113.7'], $config->allow());
        self::assertSame(1, $config->keepOnHost());
        self::assertFalse($config->plainAllowed(), 'only a real true counts');
    }

    public function testTheSettingsAreChecked(): void
    {
        $config = $this->config();
        $config->setKeepOnHost(0);
        $config->setKeepOnHost(20);
        $config->setPlainAllowed(true);

        self::assertSame(20, $config->keepOnHost());
        self::assertTrue($config->plainAllowed());
        foreach ([-1, 21] as $count) {
            try {
                $config->setKeepOnHost($count);
                self::fail("keepOnHost $count must be refused");
            } catch (InvalidArgumentException) {
                self::assertSame(20, $config->keepOnHost(), 'unchanged');
            }
        }
    }

    /**
     * @return array<string, array{string, string, bool}> entry, address, whether it matches
     */
    public static function addresses(): array
    {
        return [
            'the same IPv4 address' => ['203.0.113.7', '203.0.113.7', true],
            'another IPv4 address' => ['203.0.113.7', '203.0.113.8', false],
            'inside a /24' => ['198.51.100.0/24', '198.51.100.250', true],
            'outside a /24' => ['198.51.100.0/24', '198.51.101.1', false],
            'inside a /20, bits that do not end on a byte' => ['10.0.16.0/20', '10.0.31.255', true],
            'outside a /20' => ['10.0.16.0/20', '10.0.32.0', false],
            'a /0 lets every IPv4 address in' => ['0.0.0.0/0', '8.8.8.8', true],
            'the same IPv6 address' => ['2001:db8::1', '2001:0db8:0:0:0:0:0:1', true],
            'inside an IPv6 /32' => ['2001:db8::/32', '2001:db8:ffff::5', true],
            'outside an IPv6 /32' => ['2001:db8::/32', '2001:db9::1', false],
            'an IPv6 address never matches an IPv4 entry' => ['203.0.113.7', '::1', false],
            'an IPv4 address never matches an IPv6 entry' => ['2001:db8::/32', '203.0.113.7', false],
            'an address that is none' => ['203.0.113.7', 'not-an-address', false],
        ];
    }

    #[DataProvider('addresses')]
    public function testAnAddressIsMatchedAgainstAnEntry(string $entry, string $address, bool $expected): void
    {
        self::assertSame($expected, AgentConfig::matches($entry, $address));
    }

    public function testTheListOfAddressesDecidesWhoMayAsk(): void
    {
        $config = $this->config();
        $config->setAllow([' 203.0.113.7 ', '198.51.100.0/24', '', '203.0.113.7']);

        self::assertSame(['203.0.113.7', '198.51.100.0/24'], $config->allow(), 'trimmed, without the empty and the double');
        self::assertTrue($config->allows('198.51.100.9'));
        self::assertFalse($config->allows('192.0.2.1'));

        $config->setAllow([]);
        self::assertTrue($config->allows('192.0.2.1'), 'an empty list lets everybody ask');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badEntries(): array
    {
        return ['a name' => ['example.com'], 'a range without an address' => ['/24'], 'a prefix that is too long' => ['203.0.113.0/33'], 'an IPv6 prefix that is too long' => ['2001:db8::/129'], 'a prefix that is no number' => ['203.0.113.0/x']];
    }

    #[DataProvider('badEntries')]
    public function testAnEntryThatIsNoAddressIsRefused(string $entry): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->config()->setAllow([$entry]);
    }
}
