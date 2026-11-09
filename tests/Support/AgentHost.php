<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use ThreadSafe\Engine\Budget;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\RemoteSettings;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Remote\AgentClient;
use ThreadSafe\Web\Agent;
use ThreadSafe\Web\AgentConfig;
use ThreadSafe\Web\BackupTarget;
use ThreadSafe\Web\BackupWorkspace;

/**
 * A site with an agent that is paired and switched on, and the central side's way to reach it without a network: for the tests of the client and of the remote
 * run. The site is a small generic one; its backups are not encrypted (the agent of the app refuses that at pairing, the tests make the profile directly).
 */
final class AgentHost
{
    public const string URL = 'https://shop.example/tools/threadsafe-backup.php';

    public string $site;

    public BackupWorkspace $workspace;

    public AgentConfig $config;

    public string $secret;

    public Agent $agent;

    public LoopbackTransport $transport;

    /** The clock of both sides: close to the real time (the records of a run are stamped with it), moved by the test. */
    public int $now;

    public function __construct(string $base, string $profileId = 'shop')
    {
        $this->now = time();
        $this->site = $base . '/site';
        mkdir($this->site . '/tools', 0777, true);
        file_put_contents($this->site . '/index.php', '<?php echo 1;');
        file_put_contents($this->site . '/page.txt', str_repeat('The agent backs this up. ', 400));
        file_put_contents($this->site . '/tools/threadsafe-backup.php', '<?php // the tool');
        $this->workspace = BackupWorkspace::in($this->site . '/tools');
        $profile = (new BackupTarget($this->site . '/tools'))->profile($this->site, 'generic', $this->workspace->storage(), null, [], 1048576);
        $this->workspace->profiles()->save(SiteProfile::fromArray([...$profile->toArray(), 'id' => $profileId, 'name' => $profileId]));
        $this->config = AgentConfig::in($this->workspace->data());
        $this->secret = $this->config->pair($profileId);
        $this->config->enable(true);
        $this->agent = new Agent($this->workspace, PluginRegistry::load(), fn (): int => $this->now, static fn (): Budget => Budget::unlimited(), '1.2.3');
        $this->transport = new LoopbackTransport($this->agent);
    }

    public function settings(): RemoteSettings
    {
        return new RemoteSettings(self::URL, $this->secret);
    }

    public function client(): AgentClient
    {
        return new AgentClient($this->settings(), $this->transport, fn (): int => $this->now);
    }

    /** Makes a whole backup on the site through the agent and returns its name. */
    public function backup(): string
    {
        $client = $this->client();
        $run = $client->start()['run'];
        for ($i = 0; $i < 400; ++$i) {
            $answer = $client->tick($run);
            if ($answer['status'] === 'finished') {
                return $answer['backup'];
            }
        }

        throw new \RuntimeException('The backup on the site did not finish.');
    }
}
