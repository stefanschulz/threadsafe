<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\Agent;
use ThreadSafe\Web\AgentConfig;
use ThreadSafe\Web\AgentSignature;
use ThreadSafe\Web\BackupTarget;
use ThreadSafe\Web\BackupWorkspace;
use ThreadSafe\Web\Request;
use ThreadSafe\Web\Response;

/**
 * The agent of the backup tool answering requests, without a web server (docs/PHASE13.md 3.1): nothing for a stranger, the signed requests, and a
 * whole backup made through the API, listed, fetched with Range, held elsewhere and tidied up on the site.
 */
final class AgentTest extends TestCase
{
    use TempDirectory;

    private const string ADDRESS = '203.0.113.5';

    private string $site;

    private BackupWorkspace $workspace;

    /** The clock of the agent: close to the real one (the records of a run are stamped with the real time), moved by the tests. */
    private int $now = 0;

    private string $secret = '';

    protected function setUp(): void
    {
        $this->now = time();
        $base = $this->tempDirectory();
        $this->site = $base . '/site';
        mkdir($this->site . '/tools', 0777, true);
        file_put_contents($this->site . '/index.php', '<?php echo 1;');
        file_put_contents($this->site . '/page.txt', str_repeat('The agent backs this up. ', 200));
        file_put_contents($this->site . '/tools/threadsafe-backup.php', '<?php // the tool');
        $this->workspace = BackupWorkspace::in($this->site . '/tools');
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /** The tool has been set up for an agent: a profile under the id of the central profile, a pairing, switched on. */
    private function pairedAndOn(): AgentConfig
    {
        $profile = (new BackupTarget($this->site . '/tools'))->profile($this->site, 'generic', $this->workspace->storage(), null, [], 1048576);
        $this->workspace->profiles()->save(SiteProfile::fromArray([...$profile->toArray(), 'id' => 'shop', 'name' => 'shop']));
        $config = AgentConfig::in($this->workspace->data());
        $this->secret = $config->pair('shop');
        $config->enable(true);

        return $config;
    }

    private function agent(): Agent
    {
        return new Agent($this->workspace, PluginRegistry::load(), fn (): int => $this->now, static fn (): Budget => Budget::unlimited(), '1.2.3');
    }

    /**
     * A request as the central side makes it.
     *
     * @param array<string, string> $query
     * @param array<string, string> $headers extra headers, lower case, e.g. 'range'
     */
    private function call(string $method, array $query, string $body = '', array $headers = [], bool $sign = true, bool $https = true, string $address = self::ADDRESS, ?string $secret = null, ?int $time = null): Response
    {
        $uri = '/tools/threadsafe-backup.php?' . http_build_query($query);
        $signed = [];
        if ($sign) {
            foreach (AgentSignature::headers($secret ?? $this->secret, $method, $uri, $body, $time ?? $this->now) as $name => $value) {
                $signed[strtolower($name)] = $value;
            }
        }

        return $this->agent()->handle(new Request($method, $query, [], [], $https, $address, '/tools/threadsafe-backup.php', [...$signed, ...$headers], [], $uri, $body));
    }

    private function body(Response $response): string
    {
        if ($response->stream === null) {
            return $response->body;
        }
        ob_start();
        ($response->stream)();

        return (string) ob_get_clean();
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $data = json_decode($this->body($response), true);
        self::assertIsArray($data, $this->body($response));

        return $data;
    }

    /** Makes a whole backup through the API and returns its name. */
    private function backedUp(): string
    {
        $run = (string) $this->json($this->call('POST', ['api' => 'start']))['run'];
        for ($i = 0; $i < 400; ++$i) {
            $answer = $this->json($this->call('POST', ['api' => 'tick', 'run' => $run]));
            if ($answer['status'] === 'finished') {
                self::assertMatchesRegularExpression('/^shop_\d{8}-\d{6}_[0-9a-f]{8}$/D', (string) $answer['backup'], 'made under the id of the central profile');

                return (string) $answer['backup'];
            }
            self::assertContains($answer['status'], ['running', 'busy']);
        }
        self::fail('The backup did not finish.');
    }

    // ------------------------------------------------------------------------------------------------- a stranger gets nothing

    public function testAToolThatWasNeverSetUpAnswersLikeAMissingFileAndWritesNothing(): void
    {
        $response = $this->call('GET', ['api' => 'status'], sign: false);

        self::assertSame(404, $response->status);
        self::assertSame("Not found.\n", $response->body);
        self::assertDirectoryDoesNotExist($this->workspace->directory(), 'nothing was made for a request that may be a stranger\'s');
    }

    public function testEveryReasonNotToAnswerLooksTheSame(): void
    {
        $config = $this->pairedAndOn();
        $expected = $this->call('GET', ['api' => 'status'], sign: false);

        $config->enable(false);
        $off = $this->call('GET', ['api' => 'status']);
        $config->enable(true);
        $plain = $this->call('GET', ['api' => 'status'], https: false);
        $config->setAllow(['198.51.100.0/24']);
        $elsewhere = $this->call('GET', ['api' => 'status']);
        $config->setAllow([]);
        $wrong = $this->call('GET', ['api' => 'status'], secret: str_repeat('0', 64));

        foreach ([$expected, $off, $plain, $elsewhere, $wrong] as $response) {
            self::assertSame(404, $response->status);
            self::assertSame("Not found.\n", $response->body, 'the same words for every reason: a stranger learns nothing');
        }
        // the same signed request is good once the reasons are gone
        self::assertSame(200, $this->call('GET', ['api' => 'status'])->status);
    }

    public function testPlainHttpIsAnsweredOnlyIfThePersonOnTheSiteAllowedIt(): void
    {
        $config = $this->pairedAndOn();
        $config->setPlainAllowed(true);

        self::assertSame(200, $this->call('GET', ['api' => 'status'], https: false)->status);
    }

    public function testWrongSignaturesAreCountedAndLoggedAndHoldTheNextRequestBack(): void
    {
        $this->pairedAndOn();
        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(404, $this->call('GET', ['api' => 'status'], secret: str_repeat('1', 64))->status);
        }

        self::assertSame(404, $this->call('GET', ['api' => 'status'])->status, 'a good request right after three wrong ones waits, like a right key after three wrong keys');
        self::assertStringContainsString('wrong signature from ' . self::ADDRESS, (string) file_get_contents($this->workspace->directory() . '/agent.log'));

        $this->now += 10;
        self::assertSame(200, $this->call('GET', ['api' => 'status'])->status, 'after the wait the owner gets in');
    }

    public function testASignedRequestThatIsLateOrSeenBeforeIsToldSo(): void
    {
        $this->pairedAndOn();

        $late = $this->call('GET', ['api' => 'status'], time: $this->now - 500);
        self::assertSame(403, $late->status);
        self::assertSame('clock', $this->json($late)['error']);
        self::assertSame($this->now, $this->json($late)['hostTime']);

        $request = ['GET', ['api' => 'status']];
        self::assertSame(200, $this->call(...$request)->status);
        // the same time and the same nonce are not made again by the helper; a replay is the same request sent twice
        $uri = '/tools/threadsafe-backup.php?api=status';
        $headers = [];
        foreach (AgentSignature::headers($this->secret, 'GET', $uri, '', $this->now, 'a-nonce-that-is-reused-1') as $name => $value) {
            $headers[strtolower($name)] = $value;
        }
        $once = new Request('GET', ['api' => 'status'], [], [], true, self::ADDRESS, '/tools/threadsafe-backup.php', $headers, [], $uri, '');
        self::assertSame(200, $this->agent()->handle($once)->status);
        $again = $this->agent()->handle($once);
        self::assertSame(403, $again->status);
        self::assertSame('replay', $this->json($again)['error']);
    }

    public function testAnUnknownActionAndTheWrongMethodAreToldToASignedSender(): void
    {
        $this->pairedAndOn();

        self::assertSame(404, $this->call('GET', ['api' => 'nothing'])->status);
        self::assertSame('unknown', $this->json($this->call('GET', ['api' => 'nothing']))['error']);
        $wrong = $this->call('GET', ['api' => 'start']);
        self::assertSame(405, $wrong->status);
        self::assertSame(405, $this->call('POST', ['api' => 'status'])->status);
    }

    // ------------------------------------------------------------------------------------------------- status

    public function testStatusTellsTheVersionTheLimitsTheClockAndWhetherTheProfileIsThere(): void
    {
        $config = $this->pairedAndOn();
        $status = $this->json($this->call('GET', ['api' => 'status']));

        self::assertSame(AgentConfig::API_VERSION, $status['api']);
        self::assertSame('1.2.3', $status['tool']);
        self::assertSame($this->now, $status['time']);
        self::assertSame(['id' => 'shop', 'ready' => true], $status['profile']);
        self::assertTrue($status['space']['volumeOnly'], 'the figure for free space is the volume\'s, and the central side is told so');
        self::assertSame(1, $status['keepOnHost']);
        self::assertSame(0, $status['backups']);
        self::assertNull($status['run']);
        self::assertArrayHasKey('max_execution_time', $status['limits']);
        self::assertStringNotContainsString($this->secret, json_encode($status, JSON_THROW_ON_ERROR), 'nothing secret and no path');
        self::assertStringNotContainsString($this->site, json_encode($status, JSON_THROW_ON_ERROR));
        $config->unpair();
    }

    public function testStartWithoutAProfileSaysTheSetupIsNotFinished(): void
    {
        $config = AgentConfig::in($this->workspace->data());
        $this->secret = $config->pair('shop');
        $config->enable(true);

        $start = $this->call('POST', ['api' => 'start']);

        self::assertSame(409, $start->status);
        self::assertSame('no-profile', $this->json($start)['error']);
        self::assertFalse($this->json($this->call('GET', ['api' => 'status']))['profile']['ready']);
    }

    // ------------------------------------------------------------------------------------------------- a backup through the API

    public function testABackupIsMadeListedFetchedWithRangeAndHeldElsewhere(): void
    {
        $this->pairedAndOn();
        $backup = $this->backedUp();

        $list = $this->json($this->call('GET', ['api' => 'list']))['backups'];
        self::assertCount(1, $list);
        self::assertSame($backup, $list[0]['backup']);
        self::assertTrue($list[0]['complete']);
        self::assertNotSame([], $list[0]['parts']);
        $part = $list[0]['parts'][0];
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $part['sha256']);
        self::assertSame($backup . '.json', $list[0]['sidecar']['file']);

        // the part, whole: the bytes are the ones whose SHA-256 the list names
        $whole = $this->call('GET', ['api' => 'part', 'backup' => $backup, 'file' => $part['file']]);
        self::assertSame(200, $whole->status);
        $bytes = $this->body($whole);
        self::assertSame($part['sha256'], hash('sha256', $bytes));
        self::assertSame($part['size'], strlen($bytes));

        // from the middle, as a cut transfer goes on
        $from = intdiv(strlen($bytes), 2);
        $rest = $this->call('GET', ['api' => 'part', 'backup' => $backup, 'file' => $part['file']], headers: ['range' => 'bytes=' . $from . '-', 'if-range' => '"' . $part['sha256'] . '"']);
        self::assertSame(206, $rest->status);
        self::assertSame(substr($bytes, $from), $this->body($rest));

        // the sidecar file is fetched, not made again
        $sidecar = $this->call('GET', ['api' => 'part', 'backup' => $backup, 'file' => $backup . '.json']);
        self::assertSame(200, $sidecar->status);
        self::assertSame($list[0]['sidecar']['sha256'], hash('sha256', $this->body($sidecar)));

        // held elsewhere: the central side names the SHA-256 of what it holds
        $done = $this->call('POST', ['api' => 'done'], (string) json_encode(['backup' => $backup, 'parts' => [$part['file'] => $part['sha256']]]));
        self::assertSame(200, $done->status);
        self::assertSame(['kept' => [$backup], 'deleted' => []], $this->json($done));
    }

    public function testAPartThatIsNotOfThisBackupOrThisProfileIsNotFound(): void
    {
        $this->pairedAndOn();
        $backup = $this->backedUp();

        self::assertSame(404, $this->call('GET', ['api' => 'part', 'backup' => $backup, 'file' => '../../index.php'])->status);
        self::assertSame(404, $this->call('GET', ['api' => 'part', 'backup' => $backup, 'file' => 'nothing.zip'])->status);
        self::assertSame(404, $this->call('GET', ['api' => 'part', 'backup' => 'other_20261105-120000_abcdef01', 'file' => 'x.zip'])->status);
        self::assertSame(404, $this->call('GET', ['api' => 'part'])->status);
    }

    public function testHoldingABackupNeedsTheRightSha256OfEveryPart(): void
    {
        $this->pairedAndOn();
        $backup = $this->backedUp();
        $part = $this->json($this->call('GET', ['api' => 'list']))['backups'][0]['parts'][0];

        $wrong = $this->call('POST', ['api' => 'done'], (string) json_encode(['backup' => $backup, 'parts' => [$part['file'] => str_repeat('0', 64)]]));
        self::assertSame(409, $wrong->status);
        self::assertSame('mismatch', $this->json($wrong)['error']);
        self::assertSame(409, $this->call('POST', ['api' => 'done'], (string) json_encode(['backup' => $backup, 'parts' => ['another.zip' => $part['sha256']]]))->status, 'a part that is not named');
        self::assertSame(400, $this->call('POST', ['api' => 'done'], 'not json')->status);
        self::assertSame(404, $this->call('POST', ['api' => 'done'], (string) json_encode(['backup' => 'shop_20200101-000000_abcdef01', 'parts' => ['x' => 'y']]))->status);
        self::assertCount(1, $this->json($this->call('GET', ['api' => 'list']))['backups'], 'nothing was deleted by a done that did not hold');
    }

    public function testTheSiteKeepsItsNewestBackupsAfterOneIsHeldAndNeverOneThatWasNotFetched(): void
    {
        $config = $this->pairedAndOn();
        $first = $this->backedUp();
        ++$this->now;   // the name of a backup carries the second it was made in
        $second = $this->backedUp();
        ++$this->now;
        $third = $this->backedUp();
        $held = function (string $backup): Response {
            $list = $this->json($this->call('GET', ['api' => 'list']))['backups'];
            $entry = array_values(array_filter($list, static fn (array $b): bool => $b['backup'] === $backup))[0];
            $parts = [];
            foreach ($entry['parts'] as $part) {
                $parts[$part['file']] = $part['sha256'];
            }

            return $this->call('POST', ['api' => 'done'], (string) json_encode(['backup' => $backup, 'parts' => $parts]));
        };

        // the middle one is held and keepOnHost is 1: the host keeps its newest backup, which has not been fetched yet; the one that was held and
        // everything older go
        $answer = $this->json($held($second));
        self::assertSame([$second, $first], $answer['deleted']);
        self::assertSame([$third], $answer['kept'], 'a backup that is newer than the one that was held is never removed');

        $config->setKeepOnHost(0);
        $answer = $this->json($held($third));
        self::assertSame([$third], $answer['deleted'], 'keepOnHost 0: the one that was held goes too');
        self::assertSame([], $this->json($this->call('GET', ['api' => 'list']))['backups']);
    }

    public function testARunThatIsGoingOnIsTakenUpNotDoubledAndCanBeGivenUp(): void
    {
        $this->pairedAndOn();
        $first = $this->json($this->call('POST', ['api' => 'start']));
        $second = $this->json($this->call('POST', ['api' => 'start']));

        self::assertFalse($first['resumed']);
        self::assertTrue($second['resumed']);
        self::assertSame($first['run'], $second['run']);
        self::assertSame($first['run'], $this->json($this->call('GET', ['api' => 'status']))['run']['id']);

        $abort = $this->call('POST', ['api' => 'abort', 'run' => $first['run']]);
        self::assertSame(200, $abort->status);
        self::assertSame(['aborted' => true], $this->json($abort));
        self::assertSame('no-run', $this->json($this->call('POST', ['api' => 'abort', 'run' => $first['run']]))['error']);
        self::assertNull($this->json($this->call('GET', ['api' => 'status']))['run']);
    }

    public function testATickOfARunThatDoesNotExistIsNotFound(): void
    {
        $this->pairedAndOn();

        self::assertSame(404, $this->call('POST', ['api' => 'tick', 'run' => 'backup-20260101-000000-abcdef12'])->status);
        self::assertSame(404, $this->call('POST', ['api' => 'tick'])->status);
    }
}
