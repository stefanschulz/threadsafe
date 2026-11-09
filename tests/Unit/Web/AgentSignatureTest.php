<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\AgentReplayStore;
use ThreadSafe\Web\AgentSignature;
use ThreadSafe\Web\Request;

/**
 * The signature of a request to the agent (docs/PHASE13.md 3.1): a changed method, address, body, time or nonce is refused, a repeat is refused,
 * the window is 120 seconds, and a request that is not signed does not use up a nonce.
 */
final class AgentSignatureTest extends TestCase
{
    use TempDirectory;

    private const string SECRET = 'aabbccddeeff00112233445566778899aabbccddeeff00112233445566778899';

    private const int NOW = 1_800_000_000;

    private int $clock = self::NOW;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function signature(): AgentSignature
    {
        return new AgentSignature(new AgentReplayStore($this->tempDirectory() . '/replays'), fn (): int => $this->clock);
    }

    /**
     * A request as the host sees it, signed as the central side signs it.
     *
     * @param array<string, string> $change header (lower case) => value, replaced after signing
     */
    private function request(string $method = 'POST', string $uri = '/threadsafe-backup.php?api=tick', string $body = '{"run":"x"}', ?int $time = null, ?string $nonce = null, array $change = []): Request
    {
        $headers = [];
        foreach (AgentSignature::headers(self::SECRET, $method, $uri, $body, $time ?? self::NOW, $nonce) as $name => $value) {
            $headers[strtolower($name)] = $value;
        }

        return new Request($method, headers: [...$headers, ...$change], uri: $uri, body: $body);
    }

    public function testAProperlySignedRequestIsAccepted(): void
    {
        self::assertNull($this->signature()->verify(self::SECRET, $this->request()));
    }

    public function testTheSignatureHasTheShapeOfTheHeaders(): void
    {
        $headers = AgentSignature::headers(self::SECRET, 'GET', '/a?b=c', '', self::NOW, 'abcdefghijklmnop0123');

        self::assertSame((string) self::NOW, $headers['X-ThreadSafe-Time']);
        self::assertSame('abcdefghijklmnop0123', $headers['X-ThreadSafe-Nonce']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $headers['X-ThreadSafe-Signature'], 'base64url of 32 bytes, no padding');
        self::assertNotSame(AgentSignature::sign(self::SECRET, 'GET', '/a?b=c', self::NOW, 'abcdefghijklmnop0123', ''), AgentSignature::sign(self::SECRET, 'GET', '/a?b=d', self::NOW, 'abcdefghijklmnop0123', ''));
        self::assertMatchesRegularExpression('/^[0-9a-f]{24}$/D', AgentSignature::nonce());
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function changes(): array
    {
        return [
            'another method' => [['method' => 'GET']],
            'another path and query' => [['uri' => '/threadsafe-backup.php?api=start']],
        ];
    }

    /**
     * @param array<string, string> $change
     */
    #[DataProvider('changes')]
    public function testAChangedMethodOrAddressInvalidatesTheSignature(array $change): void
    {
        $signed = $this->request();
        $other = new Request($change['method'] ?? $signed->method, headers: $signed->headers, uri: $change['uri'] ?? $signed->uri, body: $signed->body);

        self::assertSame('signature', $this->signature()->verify(self::SECRET, $other));
    }

    public function testAChangedBodyInvalidatesTheSignature(): void
    {
        $signed = $this->request();

        self::assertSame('signature', $this->signature()->verify(self::SECRET, new Request('POST', headers: $signed->headers, uri: $signed->uri, body: '{"run":"y"}')));
    }

    public function testAnotherSecretIsRefused(): void
    {
        self::assertSame('signature', $this->signature()->verify(str_repeat('0', 64), $this->request()));
    }

    public function testAChangedTimeOrNonceInvalidatesTheSignature(): void
    {
        $signature = $this->signature();
        self::assertSame('signature', $signature->verify(self::SECRET, $this->request(change: ['x-threadsafe-time' => (string) (self::NOW + 1)])));
        self::assertSame('signature', $signature->verify(self::SECRET, $this->request(change: ['x-threadsafe-nonce' => 'another-nonce-123456'])));
    }

    public function testTheWindowIs120SecondsOnBothSides(): void
    {
        $signature = $this->signature();

        self::assertNull($signature->verify(self::SECRET, $this->request(time: self::NOW - 120, nonce: 'nonce-a-0123456789')));
        self::assertNull($signature->verify(self::SECRET, $this->request(time: self::NOW + 120, nonce: 'nonce-b-0123456789')));
        self::assertSame('time', $signature->verify(self::SECRET, $this->request(time: self::NOW - 121, nonce: 'nonce-c-0123456789')));
        self::assertSame('time', $signature->verify(self::SECRET, $this->request(time: self::NOW + 121, nonce: 'nonce-d-0123456789')));
    }

    public function testAStrangerWithABadTimeIsToldNothingAboutTheClock(): void
    {
        $signature = $this->signature();
        $stranger = new Request('POST', headers: [
            'x-threadsafe-time' => (string) (self::NOW - 5000),
            'x-threadsafe-nonce' => 'a-nonce-of-a-stranger-1',
            'x-threadsafe-signature' => str_repeat('A', 43),
        ], uri: '/x', body: '');

        self::assertSame('signature', $signature->verify(self::SECRET, $stranger), 'not "time": only a sender who holds the secret learns that its clock is wrong');
        self::assertSame('time', $signature->verify(self::SECRET, $this->request(time: self::NOW - 5000, nonce: 'a-nonce-of-the-owner-2')));
    }

    public function testARepeatIsRefused(): void
    {
        $signature = $this->signature();
        $request = $this->request();

        self::assertNull($signature->verify(self::SECRET, $request));
        self::assertSame('replay', $signature->verify(self::SECRET, $request), 'the same request again');
    }

    public function testARequestThatIsNotSignedDoesNotUseUpTheNonce(): void
    {
        $signature = $this->signature();
        $good = $this->request(nonce: 'a-nonce-of-the-owner-01');
        $forged = new Request('POST', headers: [...$good->headers, 'x-threadsafe-signature' => str_repeat('A', 43)], uri: $good->uri, body: $good->body);

        self::assertSame('signature', $signature->verify(self::SECRET, $forged));
        self::assertNull($signature->verify(self::SECRET, $good), 'the real request still goes through');
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function badHeaders(): array
    {
        return [
            'no time' => [['x-threadsafe-time' => '']],
            'a time with letters' => [['x-threadsafe-time' => '17x']],
            'a nonce that is too short' => [['x-threadsafe-nonce' => 'short']],
            'a nonce with a character that does not belong' => [['x-threadsafe-nonce' => "abcdefghijklmnop\n"]],
            'a signature of the wrong length' => [['x-threadsafe-signature' => 'abc']],
        ];
    }

    /**
     * @param array<string, string> $change
     */
    #[DataProvider('badHeaders')]
    public function testHeadersOfTheWrongShapeAreRefusedBeforeAnythingIsComputed(array $change): void
    {
        self::assertSame('headers', $this->signature()->verify(self::SECRET, $this->request(change: $change)));
    }

    public function testMissingHeadersAreRefused(): void
    {
        self::assertSame('headers', $this->signature()->verify(self::SECRET, new Request('GET', uri: '/x')));
        $signed = $this->request();
        $headers = $signed->headers;
        unset($headers['x-threadsafe-nonce']);
        self::assertSame('headers', $this->signature()->verify(self::SECRET, new Request('POST', headers: $headers, uri: $signed->uri, body: $signed->body)));
    }
}
