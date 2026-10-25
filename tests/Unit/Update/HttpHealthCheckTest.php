<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Update\HealthResult;
use ThreadSafe\Update\HttpHealthCheck;

/**
 * The health check after the switch: the token it stores, what the new version answers to it, and what counts as a failure.
 */
final class HttpHealthCheckTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @param array{int, array<string, string>, string}|null $answer the answer of the transport; a closure builds it from the url
     */
    private function check(string $data, array|\Closure|null $answer, string $version = '0.2.0'): HealthResult
    {
        $transport = static fn (string $url, string $host): ?array => $answer instanceof \Closure ? $answer($url, $host, $data) : $answer;

        return (new HttpHealthCheck('https://example.org/threadsafe/', 'example.org', $transport, static fn (): int => 1800000000))->check($data, $version);
    }

    public function testTheNewVersionThatAnswersToTheTokenIsHealthy(): void
    {
        $data = $this->tempDirectory();
        $seen = [];

        $result = $this->check($data, function (string $url, string $host, string $data) use (&$seen): array {
            $seen = [$url, $host];
            $token = (string) substr($url, (int) strrpos($url, '/') + 1);
            $answer = HttpHealthCheck::answer($data, $token, '0.2.0', 1800000001);

            return [200, ['content-type' => 'application/json'], (string) json_encode($answer)];
        });

        self::assertSame(HealthResult::OK, $result->status);
        self::assertMatchesRegularExpression('#^https://example\.org/threadsafe/health/[0-9a-f]{32}$#', $seen[0]);
        self::assertSame('example.org', $seen[1]);
        self::assertFileDoesNotExist($data . '/health.json', 'the token does not stay');
    }

    public function testAVersionThatIsNotTheNewOneIsNotHealthy(): void
    {
        $result = $this->check($this->tempDirectory(), [200, [], '{"ok":true,"version":"0.1.0"}']);

        self::assertSame(HealthResult::FAILED, $result->status);
        self::assertStringContainsString('answered as version 0.1.0, not 0.2.0', $result->message);
    }

    public function testAnErrorPageIsNotHealthy(): void
    {
        $result = $this->check($this->tempDirectory(), [500, [], '<html>Whoops</html>']);

        self::assertSame(HealthResult::FAILED, $result->status);
        self::assertStringContainsString('HTTP 500', $result->message);
    }

    public function testNoAnswerAtAllIsNotHealthy(): void
    {
        $result = $this->check($this->tempDirectory(), null);

        self::assertSame(HealthResult::FAILED, $result->status);
        self::assertStringContainsString('did not answer', $result->message);
    }

    public function testAnAnswerThatIsNotJsonIsNotHealthy(): void
    {
        self::assertSame(HealthResult::FAILED, $this->check($this->tempDirectory(), [200, [], 'a page of the site'])->status);
    }

    public function testTheTokenIsStoredOnlyForTheRequestAndIsAYoungSingleUseSecret(): void
    {
        $data = $this->tempDirectory();
        file_put_contents($data . '/health.json', json_encode(['token' => 'abc123', 'expires' => 1000]));

        self::assertNull(HttpHealthCheck::answer($data, 'wrong', '0.2.0', 900), 'a wrong token');
        self::assertNull(HttpHealthCheck::answer($data, 'abc123', '0.2.0', 1001), 'an expired token');
        self::assertSame(['ok' => true, 'version' => '0.2.0'], HttpHealthCheck::answer($data, 'abc123', '0.2.0', 900));
        self::assertNull(HttpHealthCheck::answer($data, 'abc123', '0.2.0', 900), 'a token is used up by its answer');
    }

    public function testWithoutAStoredTokenNothingAnswers(): void
    {
        self::assertNull(HttpHealthCheck::answer($this->tempDirectory(), 'anything', '0.2.0'));
        $data = $this->tempDirectory();
        file_put_contents($data . '/health.json', 'garbage');
        self::assertNull(HttpHealthCheck::answer($data, 'anything', '0.2.0'));
    }
}
