<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use ThreadSafe\App\BackupService;
use ThreadSafe\App\CatalogService;
use ThreadSafe\Backup\BackupName;
use ThreadSafe\Backup\BackupState;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\RemoteSettings;
use ThreadSafe\Remote\AgentClient;
use ThreadSafe\Remote\CurlTransport;
use ThreadSafe\Tests\Support\BackupToolClient;
use ThreadSafe\Tests\Support\CliProcess;
use ThreadSafe\Tests\Support\RecordingTransport;
use ThreadSafe\Tests\Support\TableCompare;
use ThreadSafe\Build\BackupBuilder;
use ThreadSafe\Web\AgentPairing;
use ThreadSafe\Web\AgentSignature;

/**
 * The agent of the backup tool on a real WordPress site (docs/PHASE13.md 4, M3): `threadsafe-backup.php` is put into the plugin directory of the seeded WordPress 7
 * (service `wordpress7`, an Apache that gives the tool 5 seconds and 64 MB per request, like a modest shared host), set up and paired with the wizard, and the
 * central side (this container, the real command line and the real classes with curl) makes a backup of it **through the agent**: in many requests, each inside the
 * limits, fetched with the SHA-256 of the list, a transfer that is stopped goes on with `Range` and Apache answers 206, the host keeps only its newest backup
 * after `done`, the backup is in the catalog like any other, the real `age` tool opens it, and `threadsafe restore` puts it into a copy whose tables equal those of the
 * live site. A stranger, a wrong secret and a replayed request get nothing. The tests use what the earlier ones left and run in the order of this file.
 *
 * They need the seeded site (tests/stack/seed-wordpress.sh) and leave it as they found it (group `agent`, phase 4f of tests/stack/run.sh).
 */
#[Group('agent')]
final class AgentStackTest extends IntegrationTestCase
{
    private const string SITE = '/wordpress7';

    private const string PLUGIN = '/wordpress7/wp-content/plugins/threadsafe-connector';

    private const string URL = 'http://wordpress7/wp-content/plugins/threadsafe-connector/threadsafe-backup.php';

    private const string PATH = '/wp-content/plugins/threadsafe-connector/threadsafe-backup.php';

    private const string KEY = 'agent stack key 12345';

    private const string DIRECTORY = '/app/.cache/agent';

    private const string PROFILE = 'wp7';

    private static ?BackupToolClient $browser = null;

    private static string $keyFile = '';

    private static string $public = '';

    private static string $pairingFile = '';

    private static string $secret = '';

    private static ?RecordingTransport $transport = null;

    /** @var list<string> the backups the central side made, oldest first */
    private static array $backups = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_file(self::SITE . '/wp-includes/version.php')) {
            self::markTestSkipped('The seeded WordPress 7 is not mounted at ' . self::SITE . ' (tests/stack/seed-wordpress.sh).');
        }
        if (self::shell('command -v age && command -v age-keygen')[0] !== 0) {
            self::markTestSkipped('age and age-keygen are needed (the tools image has them).');
        }
    }

    /** @return array{int, string} */
    private static function shell(string $command): array
    {
        exec($command . ' 2>&1', $lines, $code);

        return [$code, implode("\n", $lines)];
    }

    private static function password(): string
    {
        return getenv('DB_ROOT_PASSWORD') ?: 'prestashop';
    }

    private static function browser(): BackupToolClient
    {
        return self::$browser ?? throw new \LogicException('The first test starts the browser.');
    }

    private static function transport(): RecordingTransport
    {
        return self::$transport ??= new RecordingTransport(new CurlTransport());
    }

    /** @return string the storage directory of the tool on the site, as this container sees it */
    private static function hostStorage(): string
    {
        $found = glob(self::PLUGIN . '/.threadsafe-backup/storage-*', GLOB_ONLYDIR) ?: [];
        self::assertCount(1, $found, 'one storage with a random name on the site');

        return $found[0];
    }

    private static function centralData(): string
    {
        return self::DIRECTORY . '/central/data';
    }

    private static function removeWhatTheToolLeft(): void
    {
        exec('rm -rf ' . escapeshellarg(self::PLUGIN));
    }

    private static function found(string $pattern, string $text): string
    {
        if (preg_match($pattern, $text, $m) !== 1) {
            self::fail("Nothing on the page matches $pattern");
        }

        return $m[1];
    }

    /**
     * A signed request to the agent, as the central side makes it, with a header or a secret changed.
     *
     * @return array{int, string} status and body
     */
    private static function signed(string $method, string $query, ?string $secret = null, bool $sign = true): array
    {
        $uri = self::PATH . '?' . $query;
        $headers = ['Connection: close'];
        if ($sign) {
            foreach (AgentSignature::headers($secret ?? self::$secret, $method, $uri) as $name => $value) {
                $headers[] = "$name: $value";
            }
        }
        $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30]]);
        $body = (string) @file_get_contents('http://wordpress7' . $uri, false, $context);
        preg_match('~^HTTP/\S+ (\d+)~', (string) ($http_response_header[0] ?? ''), $m);

        return [(int) ($m[1] ?? 0), $body];
    }

    /**
     * `bin/threadsafe` of the central side, with its own data directory.
     *
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private static function cli(array $arguments, int $timeout = 900): array
    {
        putenv('THREADSAFE_DATA=' . self::centralData());
        try {
            return CliProcess::run($arguments, $timeout);
        } finally {
            putenv('THREADSAFE_DATA');
        }
    }

    /** @param (\Closure(): Budget)|null $budgets the budget of a tick; none: the process's own (a command line: no limit) */
    private static function service(?\Closure $budgets = null): BackupService
    {
        $data = self::centralData();

        return new BackupService(
            new ProfileStore($data . '/profiles'),
            PluginRegistry::load(),
            new RunStore($data . '/runs'),
            $data,
            $budgets,
            null,
            null,
            null,
            static fn (RemoteSettings $remote): AgentClient => new AgentClient($remote, self::transport()),
        );
    }

    // ------------------------------------------------------------------------------------------------- the site and the pairing

    public function testTheToolIsPairedWithTheWizardOnAHostWithTheLimitsAndStaysOffUntilItIsSwitchedOn(): void
    {
        self::removeWhatTheToolLeft();
        exec('rm -rf ' . escapeshellarg(self::DIRECTORY));
        mkdir(self::DIRECTORY . '/central', 0777, true);
        mkdir(self::PLUGIN, 0755, true);
        exec('chown www-data:www-data ' . escapeshellarg(self::PLUGIN));
        (new BackupBuilder(dirname(__DIR__, 2)))->build(self::PLUGIN . '/threadsafe-backup.php');
        file_put_contents(self::PLUGIN . '/threadsafe-backup-key.php', "<?php return '" . self::KEY . "';");
        chmod(self::PLUGIN . '/threadsafe-backup.php', 0644);
        chmod(self::PLUGIN . '/threadsafe-backup-key.php', 0644);

        // a key pair of the person who will open the backups: the host gets the public half only
        self::$keyFile = self::DIRECTORY . '/key.txt';
        [$code, $out] = self::shell('age-keygen -o ' . escapeshellarg(self::$keyFile));
        self::assertSame(0, $code, $out);
        chmod(self::$keyFile, 0600);
        [$code, $public] = self::shell('age-keygen -y ' . escapeshellarg(self::$keyFile));
        self::assertSame(0, $code, $public);
        self::$public = trim($public);
        self::assertTrue(AgeKeys::isRecipient(self::$public));

        self::$browser = $browser = new BackupToolClient('http://wordpress7', self::PATH);
        $page = $browser->request('GET');
        self::assertSame(200, $page['status']);
        $plain = str_contains($page['body'], 'name="plain"') ? ['plain' => '1'] : [];
        $login = $browser->request('POST', '', ['action' => 'login', 'key' => self::KEY] + $plain);
        self::assertSame(303, $login['status'], $login['body']);

        $site = $browser->go('GET', '?step=site');
        self::assertStringContainsString('WordPress', $site['body']);
        $options = $browser->go('POST', '', ['action' => 'save-site', 'root' => '/var/www/html', 'type' => 'auto']);
        self::assertStringContainsString('Who may open this backup?', $options['body'], $options['body']);
        $summary = $browser->go('POST', '', ['action' => 'save-options', 'mode' => 'recipient', 'recipient' => self::$public, 'partMb' => '5', 'exclude' => '', 'note' => 'agent stack test', 'storage' => '']);
        self::assertStringContainsString('Back up now', $summary['body'], $summary['body']);

        // the agent: not paired, then paired (the string is on the page once), still off
        $agent = $browser->go('GET', '?step=agent');
        self::assertStringContainsString('name="agent_id"', $agent['body']);
        $paired = $browser->go('POST', '', ['action' => 'agent-pair', 'agent_id' => self::PROFILE, 'agent_url' => self::URL, 'agent_allow' => '', 'agent_keep' => '1', 'agent_plain' => '1']);
        $string = self::found('~<code>(ts1:[A-Za-z0-9_-]+)</code>~', $paired['body']);
        $decoded = AgentPairing::decode($string);
        self::assertSame(self::PROFILE, $decoded['profile']);
        self::assertSame(self::URL, $decoded['url']);
        self::assertStringContainsString('The agent is <strong>off</strong>', $paired['body']);
        self::$secret = $decoded['secret'];
        self::$pairingFile = self::DIRECTORY . '/pairing.txt';
        file_put_contents(self::$pairingFile, $string . "\n");
        chmod(self::$pairingFile, 0600);
        self::assertStringNotContainsString($decoded['secret'], $browser->go('GET', '?step=agent')['body'], 'the string is shown once');

        // paired but off: Apache answers like a file that is not there
        [$status, $body] = self::signed('GET', 'api=status');
        self::assertSame(404, $status);
        self::assertSame("Not found.\n", $body);

        $on = $browser->go('POST', '', ['action' => 'agent-on']);
        self::assertStringContainsString('The agent is on', $on['body']);
        [$status, $body] = self::signed('GET', 'api=status');
        self::assertSame(200, $status, $body);
        $answer = json_decode($body, true);
        self::assertSame(['id' => self::PROFILE, 'ready' => true], $answer['profile']);
        self::assertSame(1, $answer['api']);
        self::assertTrue($answer['space']['volumeOnly']);
        self::assertSame(5, $answer['limits']['max_execution_time'], 'Apache gives the tool 5 seconds');
        self::assertSame('64M', $answer['limits']['memory_limit']);
    }

    public function testAStrangerAWrongSecretAndARepeatedRequestGetNothingOrOnlyTheClock(): void
    {
        self::assertNotSame('', self::$secret, 'the first test paired the agent');

        [$status, $body] = self::signed('GET', 'api=status', sign: false);
        self::assertSame([404, "Not found.\n"], [$status, $body], 'no signature');
        [$status, $body] = self::signed('GET', 'api=status', secret: str_repeat('0', 64));
        self::assertSame([404, "Not found.\n"], [$status, $body], 'a wrong secret');
        [$status] = self::signed('GET', 'api=nothing');
        self::assertSame(200, self::signed('GET', 'api=status')[0], 'the owner gets in, whatever a stranger sent before');

        // a replay: the same request twice
        $uri = self::PATH . '?api=status';
        $headers = ['Connection: close'];
        foreach (AgentSignature::headers(self::$secret, 'GET', $uri) as $name => $value) {
            $headers[] = "$name: $value";
        }
        $context = stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 30]]);
        self::assertStringContainsString('"api":1', (string) file_get_contents('http://wordpress7' . $uri, false, $context));
        $again = (string) file_get_contents('http://wordpress7' . $uri, false, $context);
        self::assertStringContainsString('"error":"replay"', $again);

        // a clock that is 10 minutes off: the sender that holds the secret is told, a stranger is not
        $late = AgentSignature::headers(self::$secret, 'GET', $uri, '', time() - 600);
        $lateHeaders = ['Connection: close'];
        foreach ($late as $name => $value) {
            $lateHeaders[] = "$name: $value";
        }
        $answer = (string) file_get_contents('http://wordpress7' . $uri, false, stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $lateHeaders), 'ignore_errors' => true, 'timeout' => 30]]));
        self::assertStringContainsString('"error":"clock"', $answer);
        self::assertStringNotContainsString(self::$secret, $answer);
    }

    public function testTheCentralProfileIsAddedFromThePairingFileAndTheTestAsksTheAgent(): void
    {
        [$code, $out, $err] = self::cli(['profile', 'add', self::PROFILE, '--pairing-file=' . self::$pairingFile, '--storage=' . self::DIRECTORY . '/central/storage', '--plain-http', '--name=Seeded WordPress 7', '--no-test']);
        self::assertSame(0, $code, $out . $err);

        [$code, $out, $err] = self::cli(['profile', 'test', self::PROFILE]);

        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('answers: ThreadSafe', $out);
        self::assertStringContainsString('the profile "' . self::PROFILE . '" is ready on the site', $out);
        self::assertStringContainsString('max_execution_time 5, memory_limit 64M', $out);
        self::assertStringContainsString('The profile can be backed up.', $out);
        self::assertStringNotContainsString(self::$secret, $out);
        [, $shown] = self::cli(['profile', 'show', self::PROFILE]);
        self::assertStringNotContainsString(self::$secret, $shown);
        self::assertStringNotContainsString('ts1:', $shown);
        // the secret is in the profile file, which only its owner reads
        $file = self::centralData() . '/profiles/' . self::PROFILE . '.json';
        self::assertSame(0600, fileperms($file) & 0777);
    }

    // ------------------------------------------------------------------------------------------------- a backup through the agent

    public function testABackupIsMadeThroughTheAgentInManyRequestsEachInsideTheLimits(): void
    {
        $service = self::service();
        $run = $service->startScheduled(self::PROFILE, true);

        $finished = $service->complete($run->id);

        self::assertSame(RunStatus::Finished, $finished->status, (string) $finished->error);
        $result = $service->result($finished) ?? [];
        self::assertTrue($result['remote']);
        self::assertTrue($result['encrypted']);
        self::assertSame('ok', $result['status']);
        self::assertGreaterThan(1, count($result['parts']), 'parts of 5 MB: more than one');
        self::$backups[] = $result['backup'];

        $ticks = self::transport()->of('tick');
        self::assertGreaterThan(2, count($ticks), 'a backup of the site in steps of a few seconds takes several requests, not one');
        foreach (self::transport()->calls as $call) {
            self::assertNull($call['error'], 'no call broke off');
            self::assertContains($call['status'], [200, 206], $call['action']);
            self::assertLessThan(8.0, $call['seconds'], 'a request of the agent ended inside the limit of the host (' . $call['action'] . ')');
        }
        self::assertSame(1, count(self::transport()->of('start')));
        self::assertSame('done', end(self::transport()->calls)['action'] ?? '');
    }

    public function testTheBackupIsInTheCatalogAndItsBytesAreTheOnesOnTheSite(): void
    {
        self::assertNotSame([], self::$backups, 'the backup was made');
        $backup = self::$backups[0];

        [$code, $out, $err] = self::cli(['backups', self::PROFILE]);
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString($backup, $out);
        self::assertStringContainsString('encrypted', $out);
        [$code, $out, $err] = self::cli(['verify', self::PROFILE, $backup]);
        self::assertSame(0, $code, $out . $err);
        self::assertStringContainsString('is intact', $out);

        $central = self::DIRECTORY . '/central/storage';
        $parts = glob($central . '/' . $backup . '-p*.zip.age') ?: [];
        self::assertNotSame([], $parts);
        foreach ($parts as $part) {
            self::assertSame(hash_file('sha256', self::hostStorage() . '/' . basename($part)), hash_file('sha256', $part), basename($part) . ' is the file that the site made, byte for byte');
        }
        self::assertSame(hash_file('sha256', self::hostStorage() . '/' . $backup . '.json'), hash_file('sha256', $central . '/' . $backup . '.json'), 'the sidecar file is the site\'s, fetched and not made again');
        self::assertSame([], glob($central . '/.work-*') ?: [], 'no work directory is left');
        self::assertSame([], glob($central . '/*.zip') ?: [], 'a plain part has never been here');
    }

    public function testAStoppedTransferGoesOnWithRangeAndApacheAnswers206AndTheSiteKeepsOnlyItsNewestBackup(): void
    {
        $service = self::service();
        self::transport()->calls = [];
        self::transport()->cutNextDownloadAfter = 300000;

        $run = $service->startScheduled(self::PROFILE, true);
        $finished = $service->complete($run->id);

        self::assertSame(RunStatus::Finished, $finished->status, (string) $finished->error);
        $second = (string) ($service->result($finished)['backup'] ?? '');
        self::assertNotSame(self::$backups[0], $second);
        self::$backups[] = $second;
        $ranges = array_values(array_filter(self::transport()->of('part'), static fn (array $c): bool => $c['range'] !== null));
        self::assertNotSame([], $ranges, 'the part that was stopped went on from where it was');
        self::assertSame(206, $ranges[0]['status'], 'Apache answered the Range request with 206');
        self::assertMatchesRegularExpression('/^bytes=\d+-$/', (string) $ranges[0]['range']);

        // both backups are in the catalog; the site keeps its newest, by keepOnHost 1, and removed the older one after it was told
        $catalog = new CatalogService(new ProfileStore(self::centralData() . '/profiles'), new RunStore(self::centralData() . '/runs'));
        self::assertCount(2, $catalog->backups(self::PROFILE));
        $onSite = array_map('basename', glob(self::hostStorage() . '/' . self::PROFILE . '_*.json') ?: []);
        self::assertSame([$second . '.json'], $onSite, 'the site holds only its newest backup');
        self::assertSame([], glob(self::hostStorage() . '/' . self::$backups[0] . '*') ?: [], 'the older one is gone from the site');
    }

    public function testTheBackupOpensWithTheRealAgeToolAndRestoresIntoACopyWhoseTablesEqualTheLiveSite(): void
    {
        $backup = self::$backups[1];
        $central = self::DIRECTORY . '/central/storage';
        $names = [];
        foreach (glob($central . '/' . $backup . '-p*.zip.age') ?: [] as $part) {
            $plain = self::DIRECTORY . '/' . basename($part, '.age');
            [$code, $out] = self::shell('age -d -i ' . escapeshellarg(self::$keyFile) . ' -o ' . escapeshellarg($plain) . ' ' . escapeshellarg($part));
            self::assertSame(0, $code, $out);
            self::assertSame(0, self::shell('unzip -tq ' . escapeshellarg($plain))[0], basename($plain) . ' passes unzip -t');
            $names = [...$names, ...explode("\n", trim(self::shell('unzip -Z1 ' . escapeshellarg($plain))[1]))];
            unlink($plain);
        }
        self::assertContains('files/wp-config.php', $names);
        self::assertContains('files/wp-includes/version.php', $names);
        self::assertSame([], array_values(array_filter($names, static fn (string $n): bool => str_contains($n, 'threadsafe-backup') || str_contains($n, '.threadsafe-backup'))), 'none of the tool is in it');

        $admin = new PDO('mysql:host=db;charset=utf8mb4', 'root', self::password(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $admin->exec('DROP DATABASE IF EXISTS wp7_agentcheck');
        $admin->exec('CREATE DATABASE wp7_agentcheck CHARACTER SET utf8mb4');
        file_put_contents(self::DIRECTORY . '/db-password', self::password() . "\n");
        $copy = self::DIRECTORY . '/copy';
        exec('rm -rf ' . escapeshellarg($copy));
        $first = (glob($central . '/' . $backup . '-p01.zip.age') ?: [''])[0];

        [$code, $out, $err] = self::cli([
            'restore', $first, '--root=' . $copy, '--to=localhost:8197', '--db-host=db', '--db-name=wp7_agentcheck', '--db-user=root',
            '--db-password-file=' . self::DIRECTORY . '/db-password', '--identity-file=' . self::$keyFile, '--no-self-test', '--yes',
        ], 1800);
        self::assertSame(0, $code, $err . $out);

        $live = new PDO('mysql:host=db;dbname=wp7;charset=utf8mb4', 'root', self::password(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $restored = new PDO('mysql:host=db;dbname=wp7_agentcheck;charset=utf8mb4', 'root', self::password(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (['wp_posts', 'wp_postmeta', 'wp_users', 'wp_usermeta', 'wp_terms', 'wp_term_taxonomy', 'wp_term_relationships', 'wp_comments'] as $table) {
            self::assertSame(TableCompare::fingerprint($live, $table, true), TableCompare::fingerprint($restored, $table, true), "$table of the copy equals the live site");
        }
        self::assertFileExists($copy . '/wp-config.php');
        self::assertSame(md5_file(self::SITE . '/wp-includes/version.php'), md5_file($copy . '/wp-includes/version.php'));
        self::assertFileDoesNotExist($copy . '/wp-content/plugins/threadsafe-connector/threadsafe-backup.php');

        $admin->exec('DROP DATABASE IF EXISTS wp7_agentcheck');
        exec('rm -rf ' . escapeshellarg($copy));
    }

    // ------------------------------------------------------------------------------------------------- what will not pass

    public function testAnAgentThatIsSwitchedOffEndsTheCentralRunWithWhatToDo(): void
    {
        $off = self::browser()->go('POST', '', ['action' => 'agent-off']);
        self::assertStringContainsString('The agent is off', $off['body']);

        $service = self::service();
        $run = $service->startScheduled(self::PROFILE, true);
        try {
            $service->complete($run->id);
            self::fail('an agent that is off must end the run');
        } catch (GuardFailed $e) {
            self::assertStringContainsString('does not answer', $e->getMessage());
            self::assertStringContainsString('page "The agent"', $e->getMessage());
        }
        [$code, $out] = self::cli(['profile', 'test', self::PROFILE]);
        self::assertSame(2, $code);
        self::assertStringContainsString('The agent does not answer', $out);

        $on = self::browser()->go('POST', '', ['action' => 'agent-on']);
        self::assertStringContainsString('The agent is on', $on['body']);
    }

    public function testARunThatIsGivenUpAsksTheSiteToGiveUpItsRunAndLeavesTheOlderBackup(): void
    {
        // ticks of a fraction of a second: the run is stopped after the first requests to the site, in the middle of the backup there
        $service = self::service(static fn (): Budget => Budget::seconds(0.2));
        self::transport()->calls = [];
        $run = $service->startScheduled(self::PROFILE, true);
        $client = new AgentClient((new ProfileStore(self::centralData() . '/profiles'))->load(self::PROFILE)->remote ?? throw new \LogicException('remote'), self::transport());
        $step = '';
        for ($i = 0; $i < 6 && $step !== 'run'; ++$i) {
            $service->tick($run->id);
            $step = (string) (BackupState::fromArray($service->find($run->id)->state)->remote['step'] ?? '');
        }
        self::assertSame('run', $step, 'the backup on the site is going on');
        $siteRun = (string) ($client->status()['run']['id'] ?? '');
        self::assertNotSame('', $siteRun, 'the site has a run');
        self::assertSame('running', $client->status()['run']['status'] ?? '');

        $service->abandon($run->id);

        self::assertContains('abort', array_map(static fn (array $c): string => $c['action'], self::transport()->calls), 'the site was asked to give up its run');
        $after = $client->status()['run'] ?? null;
        self::assertTrue($after === null || $after['id'] !== $siteRun, 'the run on the site is gone');
        self::assertSame([], glob(self::DIRECTORY . '/central/storage/.work-*') ?: []);
        self::assertCount(2, (new CatalogService(new ProfileStore(self::centralData() . '/profiles'), new RunStore(self::centralData() . '/runs')))->backups(self::PROFILE), 'the backups that were made stay');
        self::assertNotNull(BackupName::parse(self::$backups[1]));
    }

    // ------------------------------------------------------------------------------------------------- the end

    public function testTheToolTakesItselfOffTheSiteAndTheSiteIsAsItWas(): void
    {
        // the wizard's own clean-up belongs to a backup that the wizard made; the agent is switched off and the plugin's directory is deleted (M5 does it on uninstall)
        self::assertStringContainsString('The agent is off', self::browser()->go('POST', '', ['action' => 'agent-off'])['body']);
        self::removeWhatTheToolLeft();
        self::assertDirectoryDoesNotExist(self::PLUGIN);
        // WordPress answers for the address now (the seeded object-cache drop-in even throws for this host name): the agent does not
        [$status, $body] = self::signed('GET', 'api=status');
        self::assertNotSame(200, $status, 'there is no agent any more');
        self::assertStringNotContainsString('"api"', $body);
        exec('rm -rf ' . escapeshellarg(self::DIRECTORY));
    }
}
