<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Joomla;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Joomla\HtaccessRewriter;

/**
 * The `.htaccess` of a Joomla site and the base path: the file is Joomla's own `htaccess.txt` (Joomla 6.2).
 */
final class HtaccessRewriterTest extends TestCase
{
    private static function original(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/joomla/htaccess-6.2.txt');
    }

    public function testTheFileAsJoomlaShipsItIsRightForTheRoot(): void
    {
        $result = (new HtaccessRewriter())->rewrite(self::original(), '/');

        self::assertSame(0, $result['changes']);
        self::assertSame(self::original(), $result['content']);
        self::assertSame([], $result['warnings']);
    }

    public function testASubDirectoryActivatesRewriteBaseAndNamesItInTheAnchorsOfTheApi(): void
    {
        $result = (new HtaccessRewriter())->rewrite(self::original(), '/sub/');

        $expected = strtr(self::original(), [
            "\t# RewriteBase /\n" => "\tRewriteBase /sub/\n",
            'RewriteCond %{REQUEST_URI} ^/api/' . "\n" => 'RewriteCond %{REQUEST_URI} ^/sub/api/' . "\n",
            'RewriteCond %{REQUEST_URI} !^/api/index\.php' . "\n" => 'RewriteCond %{REQUEST_URI} !^/sub/api/index\.php' . "\n",
            'RewriteCond %{REQUEST_URI} !^/index\.php' . "\n" => 'RewriteCond %{REQUEST_URI} !^/sub/index\.php' . "\n",
        ]);
        self::assertSame($expected, $result['content'], 'four lines, nothing else');
        self::assertSame(4, $result['changes']);
    }

    public function testGoingToASubDirectoryAndBackGivesTheOriginalFile(): void
    {
        $rewriter = new HtaccessRewriter();

        $there = $rewriter->rewrite(self::original(), '/sub/')['content'];
        $back = $rewriter->rewrite($there, '/');

        self::assertSame(self::original(), $back['content']);
        self::assertSame(4, $back['changes']);
    }

    public function testFromOneSubDirectoryToAnother(): void
    {
        $rewriter = new HtaccessRewriter();
        $old = $rewriter->rewrite(self::original(), '/old/')['content'];

        $moved = $rewriter->rewrite($old, '/new/');

        self::assertSame($rewriter->rewrite(self::original(), '/new/')['content'], $moved['content']);
        self::assertStringNotContainsString('/old/', $moved['content']);
        self::assertSame(4, $moved['changes']);
    }

    public function testTheSameBaseTwiceChangesNothing(): void
    {
        $rewriter = new HtaccessRewriter();
        $once = $rewriter->rewrite(self::original(), '/sub/')['content'];

        $twice = $rewriter->rewrite($once, '/sub/');

        self::assertSame($once, $twice['content']);
        self::assertSame(0, $twice['changes']);
    }

    public function testAFileWithoutARewriteBaseLineIsWarnedAboutForASubDirectory(): void
    {
        $content = "RewriteEngine On\nRewriteRule .* index.php [L]\n";

        $result = (new HtaccessRewriter())->rewrite($content, '/sub/');

        self::assertSame($content, $result['content']);
        self::assertCount(1, $result['warnings']);
        self::assertStringContainsString('RewriteBase /sub/', $result['warnings'][0]);
        self::assertSame([], (new HtaccessRewriter())->rewrite($content, '/')['warnings']);
    }

    public function testASecondRewriteBaseOfTheSiteIsNotTouched(): void
    {
        $content = self::original() . "\n<Directory>\n\tRewriteBase /mine/\n</Directory>\n";

        $result = (new HtaccessRewriter())->rewrite($content, '/sub/');

        self::assertStringContainsString("\tRewriteBase /mine/\n", $result['content']);
        self::assertStringContainsString("\tRewriteBase /sub/\n", $result['content']);
    }

    public function testLineEndsOfAFileWithCrLfSurvive(): void
    {
        $crlf = str_replace("\n", "\r\n", self::original());

        $result = (new HtaccessRewriter())->rewrite($crlf, '/sub/');

        self::assertSame(str_replace("\n", "\r\n", (new HtaccessRewriter())->rewrite(self::original(), '/sub/')['content']), $result['content']);
        self::assertSame(4, $result['changes']);
    }

    public function testTheCurrentBaseIsTheActiveRewriteBaseOrTheRoot(): void
    {
        $rewriter = new HtaccessRewriter();

        self::assertSame('/', $rewriter->currentBase(self::original()), 'commented out: the root');
        self::assertSame('/sub/', $rewriter->currentBase("RewriteBase /sub\n"));
        self::assertSame('/', $rewriter->currentBase(''));
    }
}
