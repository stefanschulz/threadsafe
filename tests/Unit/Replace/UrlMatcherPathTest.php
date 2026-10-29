<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Replace;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Replace\DomainMapping;
use ThreadSafe\Replace\Stats;
use ThreadSafe\Replace\UrlMatcher;

/**
 * A new address with a path (docs/PHASE9.md 3.5): a site that moves from the root of its domain into a directory of another one writes
 * that directory behind the new host in every URL it replaces, in the notation of the slashes it found.
 */
final class UrlMatcherPathTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function urls(): iterable
    {
        yield 'a URL' => ['<a href="https://old.example/about/">x</a>', '<a href="https://new.example/sub/about/">x</a>'];
        yield 'a URL without a path' => ['https://old.example', 'https://new.example/sub'];
        yield 'a URL with a query' => ['http://old.example/?p=1', 'http://new.example/sub/?p=1'];
        yield 'protocol-relative' => ['<img src="//old.example/a.png">', '<img src="//new.example/sub/a.png">'];
        yield 'escaped slashes of JSON' => ['{"url":"http:\/\/old.example\/wp-content\/x.jpg"}', '{"url":"http:\/\/new.example\/sub\/wp-content\/x.jpg"}'];
        yield 'encoded slashes' => ['u=https%3A%2F%2Fold.example%2Fx', 'u=https%3A%2F%2Fnew.example%2Fsub%2Fx'];
        yield 'two of them' => ['https://old.example/a and http://old.example/b', 'https://new.example/sub/a and http://new.example/sub/b'];
        yield 'a foreign URL' => ['https://other.example/a', 'https://other.example/a'];
        yield 'an e-mail address' => ['mail admin@old.example', 'mail admin@old.example'];
    }

    #[DataProvider('urls')]
    public function testThePathIsWrittenBehindTheNewHostInTheNotationOfTheText(string $input, string $expected): void
    {
        $matcher = new UrlMatcher([new DomainMapping('old.example', 'new.example', toPath: '/sub')]);

        self::assertSame($expected, $matcher->rewrite($input, false, new Stats()));
    }

    public function testAPathOfSeveralPartsAndABareNameThatStaysBare(): void
    {
        $matcher = new UrlMatcher([new DomainMapping('old.example', 'new.example', toPath: '/a/b')]);
        $stats = new Stats();

        self::assertSame('https://new.example/a/b/x', $matcher->rewrite('https://old.example/x', false, $stats));
        self::assertSame('{"u":"https:\/\/new.example\/a\/b\/x"}', $matcher->rewrite('{"u":"https:\/\/old.example\/x"}', false, $stats));
        self::assertSame('see old.example', $matcher->rewrite('see old.example', false, $stats), 'a bare name is only counted');
        self::assertSame('see new.example', $matcher->rewrite('see old.example', true, $stats), 'and replaced as a name, without a path, when that is asked for');
    }

    public function testWithoutAPathNothingChanges(): void
    {
        $matcher = new UrlMatcher([new DomainMapping('old.example', 'new.example')]);

        self::assertSame('https://new.example/x', $matcher->rewrite('https://old.example/x', false, new Stats()));
    }

    public function testThePathIsCheckedAndCanBeAddedToAMapping(): void
    {
        $mapping = new DomainMapping('old.example', 'new.example');

        self::assertSame('/sub', $mapping->withPath('/sub/')->toPath);
        self::assertSame('', $mapping->withPath('')->toPath);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has no slash at its end');
        new DomainMapping('old.example', 'new.example', toPath: 'sub/');
    }
}
