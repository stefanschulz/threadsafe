<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Cli\Application;
use ThreadSafe\Cli\BufferedOutput;
use ThreadSafe\Cli\ToolAgentCommand;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\AgentConfig;
use ThreadSafe\Web\AgentPairing;
use ThreadSafe\Web\BackupWorkspace;

/**
 * The agent of the backup tool from a shell (docs/PHASE13.md 9.3, M1): the profile of the agent from the options of the site, the pairing string, the
 * switch, who may ask, how many backups the host keeps.
 */
final class ToolAgentCommandTest extends TestCase
{
    use TempDirectory;

    private const string URL = 'https://shop.example/wp-content/plugins/threadsafe-connector/threadsafe-backup.php';

    private string $base;

    private string $site;

    protected function setUp(): void
    {
        $this->base = $this->tempDirectory();
        $this->site = $this->base . '/site';
        mkdir($this->site, 0777, true);
        file_put_contents($this->site . '/index.php', '<?php echo 1;');
        file_put_contents($this->site . '/threadsafe-backup.php', '<?php // the tool');
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
        $output = new BufferedOutput();
        $code = (new Application([new ToolAgentCommand($this->site, 'threadsafe-backup.php', PluginRegistry::load())]))->run(['threadsafe-backup.php', 'agent', ...$arguments], $output);

        return [$code, $output->stdout(), $output->stderr()];
    }

    private function config(?string $workspace = null): AgentConfig
    {
        return AgentConfig::in(($workspace === null ? BackupWorkspace::in($this->site) : BackupWorkspace::at($workspace))->data());
    }

    /** @return list<string> the arguments of a good setup */
    private function goodSetup(string ...$more): array
    {
        return array_values(['--setup', '--profile-id=shop', '--url=' . self::URL, '--type=generic', '--no-db', '--recipient=' . AgeKeys::generate()['public'], ...$more]);
    }

    public function testWithoutAnythingItSaysThatTheAgentIsNotPaired(): void
    {
        [$code, $out] = $this->cli([]);

        self::assertSame(0, $code);
        self::assertStringContainsString('Agent: off, not paired', $out);
        self::assertStringContainsString('Addresses that may ask: every address', $out);
        self::assertStringContainsString('kept on this host after one is safe elsewhere: 1', $out);
        self::assertStringContainsString('Plain http: not answered', $out);
    }

    public function testSetupMakesTheProfilePairsAndPrintsTheStringOnceWithTheAgentOff(): void
    {
        [$code, $out, $err] = $this->cli($this->goodSetup());

        self::assertSame(0, $code, $err);
        self::assertMatchesRegularExpression('/^ts1:[A-Za-z0-9_-]+$/m', $out);
        preg_match('/^(ts1:[A-Za-z0-9_-]+)$/m', $out, $m);
        $decoded = AgentPairing::decode($m[1] ?? '');
        self::assertSame(['url' => self::URL, 'profile' => 'shop'], ['url' => $decoded['url'], 'profile' => $decoded['profile']]);
        self::assertSame($decoded['secret'], $this->config()->secret());
        self::assertFalse($this->config()->isOn());
        self::assertStringContainsString('The agent is OFF', $out);
        self::assertStringContainsString(AgentPairing::readable($decoded['fingerprint']), $out);
        self::assertTrue(BackupWorkspace::in($this->site)->profiles()->exists('shop'));

        [, $status] = $this->cli([]);
        self::assertStringNotContainsString($decoded['secret'], $status, 'the status never shows the secret');
        self::assertStringContainsString('paired with the profile "shop"', $status);
    }

    public function testSetupNeedsTheIdTheAddressAndAPublicKey(): void
    {
        [$code, , $err] = $this->cli(['--setup', '--url=' . self::URL, '--type=generic', '--no-db', '--recipient=' . AgeKeys::generate()['public']]);
        self::assertSame(1, $code);
        self::assertStringContainsString('--setup needs --profile-id', $err);

        [$code, , $err] = $this->cli(['--setup', '--profile-id=shop', '--type=generic', '--no-db', '--recipient=' . AgeKeys::generate()['public']]);
        self::assertSame(1, $code);
        self::assertStringContainsString('--setup needs --url', $err);

        [$code, , $err] = $this->cli(['--setup', '--profile-id=shop', '--url=' . self::URL, '--type=generic', '--no-db']);
        self::assertSame(1, $code);
        self::assertStringContainsString('encrypted backups only', $err);
        self::assertFalse($this->config()->isPaired());
    }

    public function testABadKeyAnIdOrAnAddressIsToldAndNothingIsPaired(): void
    {
        $key = AgeKeys::generate()['public'];
        $common = ['--setup', '--type=generic', '--no-db'];
        foreach ([
            [[...$common, '--profile-id=shop', '--url=' . self::URL, '--recipient=age1notakey'], 'public key of the age format'],
            [[...$common, '--profile-id=Not A Profile', '--url=' . self::URL, '--recipient=' . $key], 'not a valid profile id'],
            [[...$common, '--profile-id=shop', '--url=shop.example', '--recipient=' . $key], 'web address'],
        ] as [$arguments, $word]) {
            [$code, , $err] = $this->cli($arguments);
            self::assertSame(1, $code, $word);
            self::assertStringContainsString($word, $err, $word);
        }
        self::assertFalse($this->config()->isPaired());
    }

    public function testTheAgentIsSwitchedOnAndOffAndOnNeedsAPairing(): void
    {
        [$code, , $err] = $this->cli(['--on']);
        self::assertSame(1, $code);
        self::assertStringContainsString('not paired', $err);

        $this->cli($this->goodSetup());
        [$code, $out] = $this->cli(['--on']);
        self::assertSame(0, $code);
        self::assertStringContainsString('The agent is ON', $out);
        self::assertTrue($this->config()->isOn());

        [, $out] = $this->cli(['--off']);
        self::assertStringContainsString('The agent is OFF', $out);
        self::assertFalse($this->config()->isOn());
    }

    public function testTheSettingsAreSetAndShown(): void
    {
        [$code, , $err] = $this->cli(['--allow=203.0.113.7', '--allow=198.51.100.0/24', '--keep-on-host=3', '--plain-http=yes']);
        self::assertSame(0, $code, $err);

        [, $out] = $this->cli([]);
        self::assertStringContainsString('Addresses that may ask: 203.0.113.7, 198.51.100.0/24', $out);
        self::assertStringContainsString('after one is safe elsewhere: 3', $out);
        self::assertStringContainsString('Plain http: answered', $out);

        $this->cli(['--allow=']);
        self::assertSame([], $this->config()->allow(), '--allow= with nothing lets every address ask');
    }

    public function testBadSettingsAreRefused(): void
    {
        foreach ([['--allow=example.com', 'no IP address'], ['--keep-on-host=21', 'from 0 to 20'], ['--keep-on-host=many', 'whole number'], ['--plain-http=maybe', 'yes or no']] as [$option, $word]) {
            [$code, , $err] = $this->cli([$option]);
            self::assertSame(1, $code, $option);
            self::assertStringContainsString($word, $err, $option);
        }
    }

    public function testUnpairingForgetsTheSecretAndTheProfile(): void
    {
        $this->cli($this->goodSetup());
        $this->cli(['--on']);

        [$code, $out] = $this->cli(['--unpair']);

        self::assertSame(0, $code);
        self::assertStringContainsString('pairing is forgotten', $out);
        self::assertFalse($this->config()->isPaired());
        self::assertFalse($this->config()->isOn());
        self::assertFalse(BackupWorkspace::in($this->site)->profiles()->exists('shop'));
    }

    public function testOnlyOneActionAtATime(): void
    {
        [$code, , $err] = $this->cli(['--on', '--off']);

        self::assertSame(1, $code);
        self::assertStringContainsString('Give one of', $err);
    }

    public function testAWorkspaceThatIsNamedHoldsTheStateThere(): void
    {
        $workspace = $this->base . '/private/workspace';
        mkdir($this->base . '/private');

        [$code, , $err] = $this->cli($this->goodSetup('--workspace=' . $workspace));

        self::assertSame(0, $code, $err);
        self::assertTrue($this->config($workspace)->isPaired());
        self::assertFalse($this->config()->isPaired(), 'not in the workspace next to the tool');
        [, $out] = $this->cli(['--workspace=' . $workspace]);
        self::assertStringContainsString('(named)', $out);
    }

    public function testAWorkspaceInsideTheDirectoryOfTheToolIsRefused(): void
    {
        [$code, , $err] = $this->cli(['--workspace=' . $this->site . '/inside']);

        self::assertSame(1, $code);
        self::assertStringContainsString('--workspace cannot be used', $err);
        self::assertStringContainsString('inside the directory of the tool', $err);
    }
}
