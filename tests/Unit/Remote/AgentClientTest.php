<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Remote;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Profile\RemoteSettings;
use ThreadSafe\Remote\AgentClient;
use ThreadSafe\Remote\AgentException;
use ThreadSafe\Remote\AgentResponse;
use ThreadSafe\Remote\AgentTransport;
use ThreadSafe\Tests\Support\AgentHost;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The central side's client of the agent (docs/PHASE13.md 3.1, 3.3), against an agent in the same process: signed calls, the answers read the way the agent writes
 * them, a part fetched with Range, resumed where a transfer broke off and checked against the SHA-256 that was listed.
 */
final class AgentClientTest extends TestCase
{
    use TempDirectory;

    private AgentHost $host;

    private string $work;

    protected function setUp(): void
    {
        $base = $this->tempDirectory();
        $this->host = new AgentHost($base);
        $this->work = $base . '/work';
        mkdir($this->work);
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    public function testStatusTellsWhatTheAgentSays(): void
    {
        $status = $this->host->client()->status();

        self::assertSame(1, $status['api']);
        self::assertSame('1.2.3', $status['tool']);
        self::assertSame(['id' => 'shop', 'ready' => true], $status['profile']);
        self::assertSame($this->host->now, $status['time']);
    }

    public function testTheCallsAreSignedAndTheAddressIsWhatTheSignatureCovers(): void
    {
        $this->host->client()->status();
        $this->host->client()->start();

        $log = $this->host->transport->log;
        self::assertSame('GET', $log[0]['method']);
        self::assertSame('/tools/threadsafe-backup.php?api=status', $log[0]['uri']);
        self::assertArrayHasKey('x-threadsafe-signature', $log[0]['headers']);
        self::assertStringStartsWith('ThreadSafe/', $log[0]['headers']['user-agent']);
        self::assertNotSame($log[0]['headers']['x-threadsafe-nonce'], $log[1]['headers']['x-threadsafe-nonce'], 'a new nonce for every call');
        self::assertSame(200, $log[0]['status']);
    }

    public function testABackupIsMadeListedAndHeldThroughTheClient(): void
    {
        $client = $this->host->client();
        $backup = $this->host->backup();

        $list = $client->backups();
        self::assertCount(1, $list);
        self::assertSame($backup, $list[0]['backup']);
        self::assertTrue($list[0]['complete']);
        $parts = [];
        foreach ($list[0]['parts'] as $part) {
            $parts[$part['file']] = $part['sha256'];
        }

        self::assertSame(['kept' => [$backup], 'deleted' => []], $client->done($backup, $parts));
    }

    public function testAPartIsFetchedWholeAndIsTheOneThatWasListed(): void
    {
        $client = $this->host->client();
        $backup = $this->host->backup();
        $part = $client->backups()[0]['parts'][0];

        $client->fetch($backup, $part['file'], $this->work . '/part.zip', $part['size'], $part['sha256']);

        self::assertSame($part['sha256'], hash_file('sha256', $this->work . '/part.zip'));
        self::assertSame($part['size'], filesize($this->work . '/part.zip'));
    }

    public function testTheSidecarFileIsFetchedLikeAPart(): void
    {
        $client = $this->host->client();
        $this->host->backup();
        $sidecar = $client->backups()[0]['sidecar'];
        self::assertNotNull($sidecar);

        $client->fetch((string) $client->backups()[0]['backup'], $sidecar['file'], $this->work . '/sidecar.json', $sidecar['size'], $sidecar['sha256']);

        self::assertSame($sidecar['sha256'], hash_file('sha256', $this->work . '/sidecar.json'));
        self::assertNotNull(json_decode((string) file_get_contents($this->work . '/sidecar.json'), true));
    }

    public function testABrokenTransferGoesOnFromTheByteThatIsThereAndEndsRight(): void
    {
        $client = $this->host->client();
        $backup = $this->host->backup();
        $part = $client->backups()[0]['parts'][0];
        $this->host->transport->cutNextDownloadAfter = 700;

        try {
            $client->fetch($backup, $part['file'], $this->work . '/part.zip', $part['size'], $part['sha256']);
            self::fail('the connection was cut');
        } catch (AgentException $e) {
            self::assertSame(AgentException::UNREACHABLE, $e->kind);
            self::assertTrue($e->isTransient());
        }
        self::assertSame(700, filesize($this->work . '/part.zip'), 'what arrived stays');

        $client->fetch($backup, $part['file'], $this->work . '/part.zip', $part['size'], $part['sha256']);

        self::assertSame($part['sha256'], hash_file('sha256', $this->work . '/part.zip'));
        $log = $this->host->transport->log;
        $last = $log[count($log) - 1] ?? self::fail('the transfer made no call');
        self::assertSame('bytes=700-', $last['headers']['range'], 'the second call asked for the rest');
        self::assertSame('"' . $part['sha256'] . '"', $last['headers']['if-range']);
        self::assertSame(206, $last['status']);
    }

    public function testATransferThatTheReceiverStopsKeepsWhatArrivedAndCanBeResumed(): void
    {
        $client = $this->host->client();
        $backup = $this->host->backup();
        $part = $client->backups()[0]['parts'][0];
        $this->host->transport->chunk = 100;

        $first = $client->fetch($backup, $part['file'], $this->work . '/part.zip', $part['size'], $part['sha256'], static fn (): bool => true);

        self::assertFalse($first, 'stopped on request: not whole yet, and not an error');
        self::assertSame(100, filesize($this->work . '/part.zip'), 'the piece that arrived before the stop is kept');

        $second = $client->fetch($backup, $part['file'], $this->work . '/part.zip', $part['size'], $part['sha256']);

        self::assertTrue($second);
        self::assertSame($part['sha256'], hash_file('sha256', $this->work . '/part.zip'));
    }

    public function testAPartThatIsAlreadyWholeIsOnlyChecked(): void
    {
        $client = $this->host->client();
        $backup = $this->host->backup();
        $part = $client->backups()[0]['parts'][0];
        $client->fetch($backup, $part['file'], $this->work . '/part.zip', $part['size'], $part['sha256']);
        $calls = count($this->host->transport->log);

        $client->fetch($backup, $part['file'], $this->work . '/part.zip', $part['size'], $part['sha256']);

        self::assertSame($calls, count($this->host->transport->log), 'nothing is fetched again');
    }

    public function testABeginningThatIsLongerThanThePartOrWrongIsDiscardedAndFetchedAgain(): void
    {
        $client = $this->host->client();
        $backup = $this->host->backup();
        $part = $client->backups()[0]['parts'][0];

        file_put_contents($this->work . '/part.zip', str_repeat('x', $part['size'] + 10));   // longer than the part
        $client->fetch($backup, $part['file'], $this->work . '/part.zip', $part['size'], $part['sha256']);
        self::assertSame($part['sha256'], hash_file('sha256', $this->work . '/part.zip'));

        unlink($this->work . '/part.zip');
        file_put_contents($this->work . '/part.zip', str_repeat('y', 50));   // wrong bytes at the beginning: found by the SHA-256 at the end
        try {
            $client->fetch($backup, $part['file'], $this->work . '/part.zip', $part['size'], $part['sha256']);
            self::fail('a wrong beginning must be found');
        } catch (AgentException $e) {
            self::assertSame(AgentException::MISMATCH, $e->kind);
        }
        self::assertFileDoesNotExist($this->work . '/part.zip', 'what is wrong is deleted');
        $client->fetch($backup, $part['file'], $this->work . '/part.zip', $part['size'], $part['sha256']);
        self::assertSame($part['sha256'], hash_file('sha256', $this->work . '/part.zip'), 'and the next call starts from the beginning');
    }

    public function testAPartThatIsNotTheOneListedIsRefusedAndDeleted(): void
    {
        $client = $this->host->client();
        $backup = $this->host->backup();
        $part = $client->backups()[0]['parts'][0];

        try {
            $client->fetch($backup, $part['file'], $this->work . '/part.zip', $part['size'], str_repeat('0', 64));
            self::fail('another SHA-256 must be found');
        } catch (AgentException $e) {
            self::assertSame(AgentException::MISMATCH, $e->kind);
            self::assertStringContainsString('another SHA-256', $e->getMessage());
        }
        self::assertFileDoesNotExist($this->work . '/part.zip');
    }

    public function testAnAgentThatIsOffOrNotPairedOrWithAWrongSecretIsReportedAsRefusing(): void
    {
        $this->host->config->enable(false);
        $off = $this->catchKind(fn () => $this->host->client()->status());
        $this->host->config->enable(true);
        $wrong = new AgentClient(new RemoteSettings(AgentHost::URL, str_repeat('0', 64)), $this->host->transport, fn (): int => $this->host->now);

        self::assertSame(AgentException::REFUSED, $off);
        self::assertSame(AgentException::REFUSED, $this->catchKind(fn () => $wrong->status()));
    }

    public function testAClockThatIsOffIsToldWithTheTimeOfTheHost(): void
    {
        $late = new AgentClient($this->host->settings(), $this->host->transport, fn (): int => $this->host->now - 500);

        try {
            $late->status();
            self::fail('the clocks differ');
        } catch (AgentException $e) {
            self::assertSame(AgentException::CLOCK, $e->kind);
            self::assertSame($this->host->now, $e->details['hostTime']);
            self::assertFalse($e->isTransient());
        }
    }

    public function testAnErrorOfTheAgentIsAnApiErrorWithItsMessage(): void
    {
        try {
            $this->host->client()->tick('backup-20260101-000000-abcdef12');
            self::fail('no such run');
        } catch (AgentException $e) {
            self::assertSame(AgentException::API, $e->kind);
            self::assertSame(404, $e->getCode());
            self::assertStringContainsString('no such run', strtolower($e->getMessage()));
            self::assertFalse($e->isTransient());
        }
    }

    /**
     * @return array<string, array{AgentResponse, string, bool}> the answer, the kind of exception, whether it is transient
     */
    public static function answers(): array
    {
        return [
            'no answer at all' => [new AgentResponse(0, error: 'connection refused'), AgentException::UNREACHABLE, true],
            'a gateway without an answer' => [new AgentResponse(502, body: '<html>Bad gateway</html>'), AgentException::API, true],
            'a redirect' => [new AgentResponse(301, ['location' => 'https://www.shop.example/x.php']), AgentException::BAD_ANSWER, false],
            'a page of the host' => [new AgentResponse(200, body: '<html><body>Welcome to your host</body></html>'), AgentException::BAD_ANSWER, false],
            'a firewall page' => [new AgentResponse(403, body: '<html><h1>Blocked by the firewall</h1></html>'), AgentException::BAD_ANSWER, false],
            'the plain not found' => [new AgentResponse(404, body: "Not found.\n"), AgentException::REFUSED, false],
            'an error of the agent that the host turned into 500' => [new AgentResponse(500, body: '{"error":"failed","message":"The agent could not do what was asked."}'), AgentException::API, true],
        ];
    }

    #[DataProvider('answers')]
    public function testWhatIsNotAGoodAnswerSaysWhatToDo(AgentResponse $answer, string $kind, bool $transient): void
    {
        $canned = new class($answer) implements AgentTransport {
            public function __construct(private readonly AgentResponse $answer)
            {
            }

            public function send(string $method, string $url, array $headers = [], string $body = '', ?callable $sink = null, int $timeout = 60): AgentResponse
            {
                return $this->answer;
            }
        };
        $client = new AgentClient($this->host->settings(), $canned);

        try {
            $client->status();
            self::fail('this is not a good answer');
        } catch (AgentException $e) {
            self::assertSame($kind, $e->kind);
            self::assertSame($transient, $e->isTransient());
            self::assertStringNotContainsString($this->host->secret, $e->getMessage());
        }
    }

    public function testTheSecretNeverReachesAMessageEvenWhenAPageRepeatsTheRequest(): void
    {
        $echo = new class($this->host->secret) implements AgentTransport {
            public function __construct(private readonly string $secret)
            {
            }

            public function send(string $method, string $url, array $headers = [], string $body = '', ?callable $sink = null, int $timeout = 60): AgentResponse
            {
                return new AgentResponse(200, body: '<html>You asked: ' . $this->secret . '</html>');
            }
        };

        try {
            (new AgentClient($this->host->settings(), $echo))->status();
            self::fail('not JSON');
        } catch (AgentException $e) {
            self::assertStringNotContainsString($this->host->secret, $e->getMessage());
            self::assertStringContainsString('***', $e->getMessage());
        }
    }

    private function catchKind(callable $call): string
    {
        try {
            $call();
        } catch (AgentException $e) {
            return $e->kind;
        }

        return 'none';
    }
}
