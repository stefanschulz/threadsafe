<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Replace;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Replace\DomainMapping;
use ThreadSafe\Replace\UrlMatcher;
use ThreadSafe\Replace\ValueRewriter;

final class ValueRewriterTest extends TestCase
{
    private function rewriter(): ValueRewriter
    {
        return new ValueRewriter(new UrlMatcher([new DomainMapping('old.test', 'new.example')]));
    }

    public function testPlainText(): void
    {
        $result = $this->rewriter()->rewrite('<a href="http://old.test/x">x</a> and info@old.test');

        self::assertSame('<a href="http://new.example/x">x</a> and info@old.test', $result->value);
        self::assertTrue($result->changed);
        self::assertSame(1, $result->stats->urlsReplaced);
        self::assertSame(1, $result->stats->emailsSkipped);
    }

    public function testValueWithoutDomainIsReturnedUntouched(): void
    {
        $result = $this->rewriter()->rewrite('a:1:{s:1:"a";s:1:"b";}');

        self::assertSame('a:1:{s:1:"a";s:1:"b";}', $result->value);
        self::assertFalse($result->changed);
        self::assertFalse($result->stats->hasFindings());
    }

    public function testSerializedValue(): void
    {
        $result = $this->rewriter()->rewrite(serialize(['url' => 'http://old.test/x', 'n' => 3]));

        self::assertSame(serialize(['url' => 'http://new.example/x', 'n' => 3]), $result->value);
    }

    public function testJsonValue(): void
    {
        $result = $this->rewriter()->rewrite('{"url":"http:\/\/old.test\/x","n":3}');

        self::assertSame('{"url":"http:\/\/new.example\/x","n":3}', $result->value);
    }

    public function testSerializedInsideJsonInsideSerialized(): void
    {
        $inner = ['u' => 'http://old.test/y'];
        $in = serialize(['opt' => json_encode(['s' => serialize($inner)]), 'x' => 'http://old.test/z']);
        $expectedInner = ['u' => 'http://new.example/y'];
        $expected = serialize(['opt' => json_encode(['s' => serialize($expectedInner)]), 'x' => 'http://new.example/z']);

        $result = $this->rewriter()->rewrite($in);

        self::assertSame($expected, $result->value);
        self::assertSame(2, $result->stats->urlsReplaced);
    }

    public function testBrokenSerializedValueIsNotTouched(): void
    {
        // The length prefix does not match: a text replacement would make it worse.
        $broken = 'a:1:{s:3:"http://old.test/";s:1:"x";}';
        $result = $this->rewriter()->rewrite($broken);

        self::assertSame($broken, $result->value);
        self::assertFalse($result->changed);
        self::assertSame(1, $result->stats->unparseable);
        self::assertSame(0, $result->stats->urlsReplaced);
    }

    public function testTextThatStartsLikeJsonIsTreatedAsText(): void
    {
        $result = $this->rewriter()->rewrite('[b]see[/b] http://old.test/x');

        self::assertSame('[b]see[/b] http://new.example/x', $result->value);
        self::assertSame(0, $result->stats->unparseable);
    }

    public function testBareDomainsOnlyWhenAllowed(): void
    {
        self::assertSame('old.test', $this->rewriter()->rewrite('old.test')->value);
        self::assertSame(1, $this->rewriter()->rewrite('old.test')->stats->bareFound);
        self::assertSame('new.example', $this->rewriter()->rewrite('old.test', replaceBare: true)->value);
    }

    public function testBareDomainInsideSerializedValueFollowsTheFlag(): void
    {
        $in = serialize(['host' => 'old.test']);

        self::assertSame($in, $this->rewriter()->rewrite($in)->value);
        self::assertSame(serialize(['host' => 'new.example']), $this->rewriter()->rewrite($in, replaceBare: true)->value);
    }

    public function testTooDeepNestingIsNotTouched(): void
    {
        $value = 'http://old.test/';
        for ($i = 0; $i < 12; ++$i) {
            $value = json_encode(['x' => $value], JSON_THROW_ON_ERROR);
        }
        $result = $this->rewriter()->rewrite($value);

        self::assertGreaterThan(0, $result->stats->unparseable);
        self::assertSame($value, $result->value);
    }

    public function testWithoutCodecsEverythingIsText(): void
    {
        $rewriter = new ValueRewriter(new UrlMatcher([new DomainMapping('old.test', 'new.example')]), []);

        self::assertSame('{"u":"http://new.example/"}', $rewriter->rewrite('{"u":"http://old.test/"}')->value);
    }
}
