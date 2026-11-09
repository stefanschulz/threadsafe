<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Tests\Support\ToolClient;
use ThreadSafe\Web\AgentConfig;
use ThreadSafe\Web\AgentPairing;
use ThreadSafe\Web\BackupWizard;
use ThreadSafe\Web\BackupWorkspace;
use ThreadSafe\Web\Response;

/**
 * The page "The agent" of the wizard (docs/PHASE13.md 9.3, M1): the way to pair the tool with a central ThreadSafe on a host without a shell.
 */
final class BackupWizardAgentTest extends TestCase
{
    use TempDirectory;

    private const string KEY = 'correct horse battery';

    private string $web;

    protected function setUp(): void
    {
        $this->web = $this->tempDirectory() . '/site';
        mkdir($this->web, 0777, true);
        file_put_contents($this->web . '/index.php', '<?php echo 1;');
        file_put_contents($this->web . '/threadsafe-backup.php', '<?php // the tool');
        file_put_contents($this->web . '/threadsafe-backup-key.php', "<?php return '" . self::KEY . "';");
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function client(): ToolClient
    {
        $wizard = new BackupWizard($this->web, PluginRegistry::load(), 'threadsafe-backup.php', static fn (): int => 1_800_000_000, static fn (): Budget => Budget::unlimited());

        return new ToolClient($wizard->handle(...), BackupWizard::COOKIE, '/tools/threadsafe-backup.php');
    }

    /**
     * Logs in and goes through the site and the options to the summary.
     *
     * @param array<string, string> $options
     */
    private function atTheSummary(array $options): ToolClient
    {
        $client = $this->client();
        $client->get();
        $client->post(['action' => 'login', 'key' => self::KEY], [], true, false);
        $client->post(['action' => 'save-site', 'root' => $this->web, 'type' => 'auto', 'no_db' => '1']);
        $summary = $client->post([...['action' => 'save-options', 'mode' => 'none', 'plain' => '1', 'partMb' => '1', 'exclude' => '', 'note' => '', 'storage' => ''], ...$options]);
        self::assertStringContainsString('Back up now', $summary->body);

        return $client;
    }

    private function encrypted(): ToolClient
    {
        return $this->atTheSummary(['mode' => 'recipient', 'recipient' => AgeKeys::generate()['public']]);
    }

    private function config(): AgentConfig
    {
        return AgentConfig::in(BackupWorkspace::in($this->web)->data());
    }

    private function pairingOf(Response $page): string
    {
        self::assertMatchesRegularExpression('~<code>ts1:[A-Za-z0-9_-]+</code>~', $page->body, 'the pairing string is on the page');
        preg_match('~<code>(ts1:[A-Za-z0-9_-]+)</code>~', $page->body, $m);

        return $m[1] ?? '';
    }

    public function testThePageIsOnTheSummaryAndNeedsTheOptionsFirst(): void
    {
        $client = $this->encrypted();
        self::assertStringContainsString('href="?step=agent"', $client->get(['step' => 'summary'])->body);

        $fresh = $this->client();
        $fresh->get();
        $fresh->post(['action' => 'login', 'key' => self::KEY], [], true, false);
        $redirected = $fresh->get(['step' => 'agent']);
        self::assertStringContainsString('Directory of the site', $redirected->body, 'the site and the options come first');
    }

    public function testAPlainProfileCannotBePairedAndThePageSaysWhy(): void
    {
        $client = $this->atTheSummary([]);   // not encrypted

        $page = $client->get(['step' => 'agent']);

        self::assertStringContainsString('encrypted backups only', $page->body);
        self::assertStringNotContainsString('name="agent_id"', $page->body, 'no form to pair: the options come first');
        self::assertFalse($this->config()->isPaired());
    }

    public function testPairingShowsTheStringOnceAndLeavesTheAgentOff(): void
    {
        $client = $this->encrypted();
        self::assertStringContainsString('name="agent_id"', $client->get(['step' => 'agent'])->body);

        $page = $client->post(['action' => 'agent-pair', 'agent_id' => 'shop', 'agent_url' => 'https://shop.example/tools/threadsafe-backup.php', 'agent_allow' => "203.0.113.7\n198.51.100.0/24", 'agent_keep' => '2', 'agent_plain' => '']);

        $decoded = AgentPairing::decode($this->pairingOf($page));
        self::assertSame('shop', $decoded['profile']);
        self::assertSame('https://shop.example/tools/threadsafe-backup.php', $decoded['url']);
        self::assertStringContainsString('The agent is <strong>off</strong>', $page->body);
        self::assertSame(['203.0.113.7', '198.51.100.0/24'], $this->config()->allow());
        self::assertSame(2, $this->config()->keepOnHost());
        self::assertTrue(BackupWorkspace::in($this->web)->profiles()->exists('shop'), 'the profile of the agent');

        $later = $client->get(['step' => 'agent']);
        self::assertStringNotContainsString($decoded['secret'], $later->body, 'the string is shown once');
        self::assertStringNotContainsString('ts1:', $later->body);
        self::assertStringContainsString(AgentPairing::readable($decoded['fingerprint']), $later->body, 'the fingerprint is shown for the comparison');
    }

    public function testTheAgentIsSwitchedOnAndOffByThePerson(): void
    {
        $client = $this->encrypted();
        $client->post(['action' => 'agent-pair', 'agent_id' => 'shop', 'agent_url' => 'https://shop.example/x.php', 'agent_allow' => '', 'agent_keep' => '1']);
        self::assertFalse($this->config()->isOn());

        $on = $client->post(['action' => 'agent-on']);
        self::assertTrue($this->config()->isOn());
        self::assertStringContainsString('The agent is on', $on->body);

        $off = $client->post(['action' => 'agent-off']);
        self::assertFalse($this->config()->isOn());
        self::assertStringContainsString('The agent is off', $off->body);
    }

    public function testSwitchingOnNeedsAPairing(): void
    {
        $client = $this->encrypted();

        $page = $client->post(['action' => 'agent-on']);

        self::assertStringContainsString('Pair the agent first', $page->body);
        self::assertFalse($this->config()->isOn());
    }

    public function testBadValuesAreToldAndNothingIsPaired(): void
    {
        $client = $this->encrypted();

        self::assertStringContainsString('lower-case letters', $client->post(['action' => 'agent-pair', 'agent_id' => 'Not A Profile', 'agent_url' => 'https://shop.example/x.php'])->body);
        self::assertStringContainsString('is no IP address', $client->post(['action' => 'agent-pair', 'agent_id' => 'shop', 'agent_url' => 'https://shop.example/x.php', 'agent_allow' => 'example.com'])->body);
        self::assertStringContainsString('from 0 to 20', $client->post(['action' => 'agent-pair', 'agent_id' => 'shop', 'agent_url' => 'https://shop.example/x.php', 'agent_keep' => '99'])->body);
        self::assertStringContainsString('web address', $client->post(['action' => 'agent-pair', 'agent_id' => 'shop', 'agent_url' => 'shop.example'])->body);
        self::assertFalse($this->config()->isPaired());
    }

    public function testThePageSavesTheSettingsSyncsAndForgetsThePairing(): void
    {
        $client = $this->encrypted();
        $client->post(['action' => 'agent-pair', 'agent_id' => 'shop', 'agent_url' => 'https://shop.example/x.php', 'agent_keep' => '1']);

        $saved = $client->post(['action' => 'agent-save', 'agent_allow' => '192.0.2.9', 'agent_keep' => '0', 'agent_plain' => '1']);
        self::assertStringContainsString('Saved', $saved->body);
        self::assertSame(['192.0.2.9'], $this->config()->allow());
        self::assertSame(0, $this->config()->keepOnHost());
        self::assertTrue($this->config()->plainAllowed());

        self::assertStringContainsString('are the ones of the agent now', $client->post(['action' => 'agent-sync'])->body);

        $gone = $client->post(['action' => 'agent-unpair']);
        self::assertStringContainsString('The pairing is forgotten', $gone->body);
        self::assertFalse($this->config()->isPaired());
        self::assertFalse(BackupWorkspace::in($this->web)->profiles()->exists('shop'));
    }

    public function testThePostsOfThePageNeedTheTokenOfTheSession(): void
    {
        $client = $this->encrypted();

        $response = $client->post(['action' => 'agent-pair', 'agent_id' => 'shop', 'agent_url' => 'https://shop.example/x.php', 'csrf' => 'wrong']);

        self::assertSame(403, $response->status);
        self::assertFalse($this->config()->isPaired());
    }
}
