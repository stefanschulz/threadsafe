<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Replace;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Replace\DomainMapping;
use ThreadSafe\Replace\Stats;
use ThreadSafe\Replace\UrlMatcher;

final class UrlMatcherTest extends TestCase
{
    private function matcher(string $from = 'old.test', string $to = 'new.example', ?string $scheme = null, bool $keepWww = false): UrlMatcher
    {
        return new UrlMatcher([new DomainMapping($from, $to, $scheme, $keepWww)]);
    }

    #[DataProvider('urlForms')]
    public function testReplacesUrlForms(string $input, string $expected, ?string $scheme = null, bool $keepWww = false, string $to = 'new.example'): void
    {
        $stats = new Stats();
        $result = $this->matcher('old.test', $to, $scheme, $keepWww)->rewrite($input, false, $stats);

        self::assertSame($expected, $result);
        self::assertGreaterThan(0, $stats->urlsReplaced);
        self::assertSame(0, $stats->bareReplaced);
    }

    /** @return iterable<string, array{0: string, 1: string, 2?: ?string, 3?: bool, 4?: string}> */
    public static function urlForms(): iterable
    {
        yield 'https' => ['https://old.test/shop?a=1', 'https://new.example/shop?a=1'];
        yield 'http' => ['http://old.test', 'http://new.example'];
        yield 'protocol relative' => ['<img src="//old.test/a.png">', '<img src="//new.example/a.png">'];
        yield 'upper case host and scheme' => ['HTTPS://OLD.TEST/x', 'HTTPS://new.example/x'];
        yield 'www dropped' => ['http://www.old.test/x', 'http://new.example/x'];
        yield 'www kept' => ['http://www.old.test/x', 'http://www.new.example/x', null, true];
        yield 'triple slash keeps its shape' => ['http://old.test///mails/themes/a.png', 'http://new.example///mails/themes/a.png'];
        yield 'json escaped slashes' => ['"http:\/\/old.test\/a\/b"', '"http:\/\/new.example\/a\/b"'];
        yield 'json escaped twice' => ['http:\\\\/\\\\/old.test\\\\/a', 'http:\\\\/\\\\/new.example\\\\/a'];
        yield 'url encoded' => ['?back=http%3A%2F%2Fold.test%2Fcart', '?back=http%3A%2F%2Fnew.example%2Fcart'];
        yield 'url encoded lower case' => ['http%3a%2f%2fold.test%2fcart', 'http%3a%2f%2fnew.example%2fcart'];
        yield 'url encoded target with port' => ['http%3A%2F%2Fold.test%2Fx', 'http%3A%2F%2Fnew.example%3A8443%2Fx', null, false, 'new.example:8443'];
        yield 'port in target' => ['https://old.test/x', 'https://localhost:8190/x', null, false, 'localhost:8190'];
        yield 'sentence end' => ['Visit http://old.test.', 'Visit http://new.example.'];
        yield 'in quotes' => ['href="https://old.test"', 'href="https://new.example"'];
        yield 'two urls' => ['http://old.test/a and https://old.test/b', 'http://new.example/a and https://new.example/b'];
        yield 'force https' => ['http://old.test/x', 'https://new.example/x', 'https'];
        yield 'force http' => ['https://old.test/x', 'http://new.example/x', 'http'];
        yield 'force scheme in encoded url' => ['http%3A%2F%2Fold.test', 'https%3A%2F%2Fnew.example', 'https'];
        yield 'protocol relative stays relative with forced scheme' => ['//old.test/a', '//new.example/a', 'https'];
    }

    #[DataProvider('untouched')]
    public function testLeavesOtherTextAlone(string $input): void
    {
        $stats = new Stats();

        self::assertSame($input, $this->matcher()->rewrite($input, false, $stats));
        self::assertSame(0, $stats->urlsReplaced);
        self::assertSame(0, $stats->bareReplaced);
    }

    /** @return iterable<string, array{string}> */
    public static function untouched(): iterable
    {
        yield 'sub domain' => ['http://shop.old.test/x'];
        yield 'longer top level' => ['http://old.test.evil.example/x'];
        yield 'longer name' => ['http://old.testing/x'];
        yield 'name with hyphen suffix' => ['http://old.test-shop.example/x'];
        yield 'name with prefix' => ['http://myold.test/x'];
        yield 'other domain' => ['http://other.example/x'];
        yield 'other port origin' => ['http://old.test:8080/x'];
        yield 'email address' => ['mail info@old.test now'];
        yield 'mailto link' => ['<a href="mailto:info@old.test">x</a>'];
        yield 'email in url encoded form' => ['info%40old.test'];
        yield 'empty' => [''];
    }

    public function testUrlEncodedOldDomainWithAPortIsFoundToo(): void
    {
        $matcher = $this->matcher('localhost:8190', 'new.example:8443');

        self::assertSame(
            '/redirect?back=http%3A%2F%2Fnew.example%3A8443%2Fcart',
            $matcher->rewrite('/redirect?back=http%3A%2F%2Flocalhost%3A8190%2Fcart', false, new Stats()),
        );
        self::assertSame(
            'http://new.example:8443/x',
            $matcher->rewrite('http://localhost:8190/x', false, new Stats()),
            'The plain notation still works.',
        );
        self::assertSame(
            'http%3A%2F%2Flocalhost%3A81900%2Fx',
            $matcher->rewrite('http%3A%2F%2Flocalhost%3A81900%2Fx', false, new Stats()),
            'Another port is another origin.',
        );
    }

    public function testEmailAddressesAreCountedNotReplaced(): void
    {
        $stats = new Stats();
        $result = $this->matcher()->rewrite('Write to info@old.test or see http://old.test/contact', false, $stats);

        self::assertSame('Write to info@old.test or see http://new.example/contact', $result);
        self::assertSame(1, $stats->emailsSkipped);
        self::assertSame(1, $stats->urlsReplaced);
    }

    public function testBareDomainIsReportedButNotReplacedByDefault(): void
    {
        $stats = new Stats();
        $result = $this->matcher()->rewrite('Visit old.test today', false, $stats);

        self::assertSame('Visit old.test today', $result);
        self::assertSame(1, $stats->bareFound);
        self::assertSame(0, $stats->bareReplaced);
    }

    public function testBareDomainIsReplacedWhenRequested(): void
    {
        $stats = new Stats();
        $result = $this->matcher()->rewrite('old.test', true, $stats);

        self::assertSame('new.example', $result);
        self::assertSame(1, $stats->bareReplaced);
    }

    public function testBareReplacementStillSkipsEmailAddresses(): void
    {
        $stats = new Stats();
        $result = $this->matcher()->rewrite('info@old.test and old.test', true, $stats);

        self::assertSame('info@old.test and new.example', $result);
        self::assertSame(1, $stats->emailsSkipped);
    }

    public function testBareWwwFollowsTheWwwSetting(): void
    {
        self::assertSame('new.example', $this->matcher()->rewrite('www.old.test', true, new Stats()));
        self::assertSame('www.new.example', $this->matcher(keepWww: true)->rewrite('www.old.test', true, new Stats()));
    }

    public function testDomainWithPortMatchesOnlyThatPort(): void
    {
        $matcher = $this->matcher('localhost:8090', 'shop.example');

        self::assertSame('http://shop.example/a', $matcher->rewrite('http://localhost:8090/a', false, new Stats()));
        self::assertSame('http://localhost:80901/a', $matcher->rewrite('http://localhost:80901/a', false, new Stats()));
        self::assertSame('http://localhost/a', $matcher->rewrite('http://localhost/a', false, new Stats()));
    }

    public function testMappingsDoNotChain(): void
    {
        $matcher = new UrlMatcher([new DomainMapping('a.test', 'b.test'), new DomainMapping('b.test', 'a.test')]);

        self::assertSame(
            'http://b.test/x and http://a.test/y',
            $matcher->rewrite('http://a.test/x and http://b.test/y', false, new Stats()),
        );
    }

    public function testLongerNameWinsOverShorterOne(): void
    {
        $matcher = new UrlMatcher([new DomainMapping('shop.test', 'x.test'), new DomainMapping('shop.test:8080', 'y.test')]);

        self::assertSame('http://y.test/a', $matcher->rewrite('http://shop.test:8080/a', false, new Stats()));
        self::assertSame('http://x.test/a', $matcher->rewrite('http://shop.test/a', false, new Stats()));
    }

    public function testMayContainIsCaseInsensitive(): void
    {
        self::assertTrue($this->matcher()->mayContain('see OLD.test'));
        self::assertFalse($this->matcher()->mayContain('nothing here'));
    }

    public function testBinaryAndInvalidUtf8TextIsHandled(): void
    {
        $input = "\xff\xfe http://old.test/\x00x \xc3\x28";

        self::assertSame("\xff\xfe http://new.example/\x00x \xc3\x28", $this->matcher()->rewrite($input, false, new Stats()));
    }

    public function testRejectsEmptyAndDuplicateMappings(): void
    {
        try {
            new UrlMatcher([]);
            self::fail('Empty mapping list must be rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        new UrlMatcher([new DomainMapping('a.test', 'b.test'), new DomainMapping('A.test', 'c.test')]);
    }
}
