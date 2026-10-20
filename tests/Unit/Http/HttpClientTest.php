<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Http\HttpClient;

final class HttpClientTest extends TestCase
{
    /**
     * @param array<string, array{int, array<string, string>, string}> $responses by "url|host" or "url"
     * @param list<string>                                             $requests  filled with "url|host" per request
     */
    private function client(array $responses, array &$requests = [], int $maxRedirects = 10): HttpClient
    {
        return new HttpClient(false, 5, $maxRedirects, static function (string $url, string $host) use ($responses, &$requests): ?array {
            $requests[] = "$url|$host";

            return $responses["$url|$host"] ?? $responses[$url] ?? null;
        });
    }

    public function testPlainAnswer(): void
    {
        $requests = [];
        $client = $this->client(['http://shop.test/' => [200, ['content-type' => 'text/html'], '<html></html>']], $requests);

        $result = $client->get('http://shop.test/', 'shop.test');

        self::assertTrue($result->ok());
        self::assertSame('<html></html>', $result->body);
        self::assertSame('text/html', $result->header('Content-Type'));
        self::assertSame(['http://shop.test/|shop.test'], $requests);
    }

    public function testRedirectOnTheSameHostIsFollowed(): void
    {
        $client = $this->client([
            'http://shop.test/a' => [301, ['location' => 'http://shop.test/b'], ''],
            'http://shop.test/b' => [302, ['location' => '/c'], ''],
            'http://shop.test/c' => [302, ['location' => 'd'], ''],
            'http://shop.test/d' => [200, [], 'done'],
        ]);

        $result = $client->get('http://shop.test/a', 'shop.test');

        self::assertTrue($result->ok());
        self::assertSame('http://shop.test/d', $result->url);
        self::assertSame(['http://shop.test/a', 'http://shop.test/b', 'http://shop.test/c'], $result->redirects);
    }

    public function testRedirectToAnotherHostIsAProblemNotFollowed(): void
    {
        $requests = [];
        $client = $this->client(['http://shop.test/' => [301, ['location' => 'https://old-shop.example/'], '']], $requests);

        $result = $client->get('http://shop.test/', 'shop.test');

        self::assertFalse($result->ok());
        self::assertSame('redirect to another host: https://old-shop.example/', $result->problem);
        self::assertCount(1, $requests, 'The foreign host is never contacted.');
    }

    public function testRedirectLoopIsDetected(): void
    {
        $client = $this->client([
            'http://shop.test/a' => [302, ['location' => '/b'], ''],
            'http://shop.test/b' => [302, ['location' => '/a'], ''],
        ]);

        $result = $client->get('http://shop.test/a', 'shop.test');

        self::assertStringStartsWith('redirect loop: http://shop.test/a -> http://shop.test/b -> http://shop.test/a', (string) $result->problem);
    }

    public function testTooManyRedirects(): void
    {
        $responses = [];
        for ($i = 0; $i < 10; ++$i) {
            $responses["http://shop.test/$i"] = [302, ['location' => '/' . ($i + 1)], ''];
        }

        $result = $this->client($responses, maxRedirects: 3)->get('http://shop.test/0', 'shop.test');

        self::assertStringStartsWith('too many redirects', (string) $result->problem);
    }

    public function testNoAnswer(): void
    {
        $result = $this->client([])->get('http://shop.test/', 'shop.test');

        self::assertSame('no answer from http://shop.test/', $result->problem);
        self::assertSame(0, $result->status);
    }

    public function testPinnedAddressKeepsTheConnectionAddressForRedirectsToTheShopDomain(): void
    {
        $requests = [];
        $client = $this->client([
            'http://web/' => [301, ['location' => 'http://shop.test:8290/en/'], ''],
            'http://web/en/' => [200, [], 'ok'],
        ], $requests);

        $result = $client->get('http://web/', 'shop.test:8290', pinned: true);

        self::assertTrue($result->ok());
        self::assertSame(['http://web/|shop.test:8290', 'http://web/en/|shop.test:8290'], $requests, 'Connects to the pinned address, keeps the Host header.');
    }

    public function testUnpinnedRedirectToHttpsFollowsTheLocation(): void
    {
        $requests = [];
        $client = $this->client([
            'http://shop.test/' => [301, ['location' => 'https://shop.test/'], ''],
            'https://shop.test/' => [200, [], 'secure'],
        ], $requests);

        $result = $client->get('http://shop.test/', 'shop.test');

        self::assertTrue($result->ok());
        self::assertSame(['http://shop.test/|shop.test', 'https://shop.test/|shop.test'], $requests);
    }

    public function testNon200IsNotOk(): void
    {
        $result = $this->client(['http://shop.test/' => [500, [], 'error']])->get('http://shop.test/', 'shop.test');

        self::assertFalse($result->ok());
        self::assertNull($result->problem);
        self::assertSame(500, $result->status);
    }
}
