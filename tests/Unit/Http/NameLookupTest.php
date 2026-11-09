<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Http\HttpClient;
use ThreadSafe\Http\HttpResult;
use ThreadSafe\Http\NameLookup;

final class NameLookupTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function reasons(): array
    {
        return [
            'linux resolver' => ['Failed to open stream: php_network_getaddresses: getaddrinfo for sphmus.the-loom.de failed: Temporary failure in name resolution', true],
            'unknown host' => ['php_network_getaddresses: getaddrinfo for x.test failed: Name or service not known', true],
            'windows' => ['php_network_getaddresses: getaddrinfo for x.test failed: No such host is known.', true],
            'refused' => ['Failed to open stream: Connection refused', false],
            'timed out' => ['Failed to open stream: Connection timed out', false],
            'certificate' => ['SSL operation failed with code 1. OpenSSL Error messages: certificate verify failed', false],
        ];
    }

    #[DataProvider('reasons')]
    public function testOnlyTheLookupOfTheNameCounts(string $reason, bool $expected): void
    {
        $client = new HttpClient(transport: static fn (): string => $reason);

        self::assertSame($expected, NameLookup::failed($client->get('http://x.test/', 'x.test')));
    }

    public function testAnAnswerIsNeverALookupFailure(): void
    {
        self::assertFalse(NameLookup::failed(new HttpResult(200, [], 'ok', 'http://x.test/')));
        self::assertFalse(NameLookup::failed(new HttpResult(0, [], '', 'http://x.test/', [], 'redirect loop: getaddrinfo name resolution')));
    }

    public function testEveryRequestMustHaveFailedOnTheLookup(): void
    {
        $lookup = new HttpClient(transport: static fn (): string => 'getaddrinfo for x.test failed: Temporary failure in name resolution');
        $failed = $lookup->get('http://x.test/', 'x.test');
        $answered = new HttpResult(500, [], '', 'http://x.test/');

        self::assertTrue(NameLookup::allFailed([$failed, $failed]));
        self::assertFalse(NameLookup::allFailed([$failed, $answered]), 'a page that answered tells something about the site');
        self::assertFalse(NameLookup::allFailed([]));
    }

    public function testTheWarningNamesTheSiteAndWhatToDo(): void
    {
        $text = NameLookup::warning('sphmus.the-loom.de');

        self::assertStringContainsString('"sphmus.the-loom.de"', $text);
        self::assertStringContainsString('DNS', $text);
        self::assertStringContainsString('Nothing is known about the site', $text);
    }
}
