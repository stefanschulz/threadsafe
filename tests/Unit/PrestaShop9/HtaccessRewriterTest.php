<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\PrestaShop9;

use PHPUnit\Framework\TestCase;
use ThreadSafe\PrestaShop9\HtaccessRewriter;
use ThreadSafe\Replace\DomainMapping;

final class HtaccessRewriterTest extends TestCase
{
    /** A real .htaccess as PrestaShop 9.0.2 generated it for localhost:8190 (see tests/fixtures/htaccess). */
    private function generated(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/htaccess/ps9.0.2-localhost-8190.htaccess');
    }

    private function rewriter(string $to = 'shop.example', ?string $path = null): HtaccessRewriter
    {
        return new HtaccessRewriter([new DomainMapping('localhost:8190', $to)], $path);
    }

    public function testDomainIsReplacedInEveryHostCondition(): void
    {
        $original = $this->generated();

        $result = $this->rewriter()->rewrite($original);

        // Independent expectation: the generated file with the domain replaced textually.
        self::assertSame(str_replace('localhost:8190', 'shop.example', $original), $result->content);
        self::assertSame(10, $result->changedLines, 'The #Domain comment and nine host conditions.');
        self::assertSame([], $result->warnings);
        self::assertStringNotContainsString('localhost', $result->content);
    }

    public function testBasePathChange(): void
    {
        $original = $this->generated();

        $result = $this->rewriter('shop.example', '/shop/')->rewrite($original);

        $expected = str_replace(
            ['localhost:8190', 'REWRITEBASE:/]', 'ErrorDocument 404 /index.php'],
            ['shop.example', 'REWRITEBASE:/shop/]', 'ErrorDocument 404 /shop/index.php'],
            $original,
        );
        self::assertSame($expected, $result->content);
        self::assertSame(12, $result->changedLines);
    }

    public function testPathOnlyChangeWithoutAnyDomainMapping(): void
    {
        $result = (new HtaccessRewriter([], '/shop/'))->rewrite($this->generated());

        self::assertStringContainsString('#Domain: localhost:8190', $result->content);
        self::assertStringContainsString('REWRITEBASE:/shop/]', $result->content);
        self::assertStringContainsString('ErrorDocument 404 /shop/index.php?controller=404', $result->content);
        self::assertSame(2, $result->changedLines);
    }

    public function testNothingToDoLeavesTheFileIdentical(): void
    {
        $original = $this->generated();

        self::assertSame($original, (new HtaccessRewriter([], null))->rewrite($original)->content);
        self::assertSame(0, (new HtaccessRewriter([], null))->rewrite($original)->changedLines);
    }

    public function testRewritingTwiceChangesNothingMore(): void
    {
        $once = $this->rewriter('shop.example', '/shop/')->rewrite($this->generated());

        $twice = $this->rewriter('shop.example', '/shop/')->rewrite($once->content);

        self::assertSame($once->content, $twice->content);
        self::assertSame(0, $twice->changedLines);
    }

    public function testContentOutsideTheMarkersBelongsToTheOwnerAndIsNotTouched(): void
    {
        $before = "# my rules\nRedirect 301 /old http://localhost:8190/new\n\n";
        $after = "\n# more\nSetEnv X localhost:8190\n";

        $result = $this->rewriter()->rewrite($before . $this->generated() . $after);

        self::assertStringStartsWith($before, $result->content);
        self::assertStringEndsWith($after, $result->content);
        self::assertCount(1, $result->warnings, 'The old host outside the markers is reported once.');
        self::assertStringContainsString('outside the PrestaShop markers', $result->warnings[0]);
    }

    public function testWindowsLineEndingsAreKept(): void
    {
        $crlf = str_replace("\n", "\r\n", $this->generated());

        $result = $this->rewriter()->rewrite($crlf);

        self::assertSame(str_replace('localhost:8190', 'shop.example', $crlf), $result->content);
    }

    public function testFileWithoutMarkersIsLeftAloneWithAWarning(): void
    {
        $custom = "RewriteEngine on\nRewriteCond %{HTTP_HOST} ^localhost:8190\$\n";

        $result = $this->rewriter()->rewrite($custom);

        self::assertSame($custom, $result->content);
        self::assertSame(0, $result->changedLines);
        self::assertStringContainsString('no PrestaShop markers', $result->warnings[0]);
    }

    public function testSeveralDomainsAreMappedIndependently(): void
    {
        $file = "# ~~start~~ x\n\n#Domain: a.test\nRewriteRule . - [E=REWRITEBASE:/]\nRewriteCond %{HTTP_HOST} ^a.test\$\nRewriteRule x y\n\n"
            . "#Domain: b.test\nRewriteRule . - [E=REWRITEBASE:/b/]\nRewriteCond %{HTTP_HOST} ^b.test\$ [OR]\nRewriteRule x y\n"
            . "#Domain: c.test\nRewriteRule . - [E=REWRITEBASE:/c/]\nRewriteCond %{HTTP_HOST} ^c.test\$\n# ~~end~~ x\n";
        $rewriter = new HtaccessRewriter([new DomainMapping('a.test', 'a.example'), new DomainMapping('b.test', 'b.example')], '/new/');

        $result = $rewriter->rewrite($file);

        self::assertStringContainsString("#Domain: a.example\nRewriteRule . - [E=REWRITEBASE:/new/]\nRewriteCond %{HTTP_HOST} ^a.example\$\n", $result->content);
        self::assertStringContainsString("#Domain: b.example\nRewriteRule . - [E=REWRITEBASE:/new/]\nRewriteCond %{HTTP_HOST} ^b.example\$ [OR]\n", $result->content);
        self::assertStringContainsString("#Domain: c.test\nRewriteRule . - [E=REWRITEBASE:/c/]\nRewriteCond %{HTTP_HOST} ^c.test\$\n", $result->content, 'An unmapped domain keeps its host and path.');
    }

    public function testVirtualShopUrlRulesAreReportedWhenThePathChanges(): void
    {
        $file = "# ~~start~~ x\n#Domain: a.test\nRewriteRule . - [E=REWRITEBASE:/]\nRewriteRule ^de/?\$ /de/index.php [L,R]\n# ~~end~~ x\n";

        $result = (new HtaccessRewriter([new DomainMapping('a.test', 'b.test')], '/new/'))->rewrite($file);

        self::assertStringContainsString('virtual shop URLs', $result->warnings[0]);
    }
}
