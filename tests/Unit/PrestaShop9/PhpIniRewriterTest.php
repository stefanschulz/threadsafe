<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\PrestaShop9;

use PHPUnit\Framework\TestCase;
use ThreadSafe\PrestaShop9\PhpIniRewriter;
use ThreadSafe\Tests\Support\TempDirectory;

final class PhpIniRewriterTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /** @return list<string> */
    private static function directives(): array
    {
        return ['error_log', 'upload_tmp_dir', 'session.save_path'];
    }

    private function shop(): string
    {
        $root = $this->tempDirectory();
        mkdir($root . '/var/logs', 0777, true);
        mkdir($root . '/upload', 0777, true);

        return $root;
    }

    public function testOldServerPathIsMappedToTheSamePlaceInTheNewShop(): void
    {
        $root = $this->shop();
        $ini = "error_reporting = E_ALL;\nerror_log = /kunden/homepages/22/d123/htdocs/shoptest/var/logs/php-errors.log;\nmemory_limit = 512M\n";

        $result = (new PhpIniRewriter(self::directives(), $root, '/var/www/html'))->rewrite($ini);

        self::assertSame("error_reporting = E_ALL;\nerror_log = /var/www/html/var/logs/php-errors.log;\nmemory_limit = 512M\n", $result->content);
        self::assertSame([['directive' => 'error_log', 'before' => '/kunden/homepages/22/d123/htdocs/shoptest/var/logs/php-errors.log', 'after' => '/var/www/html/var/logs/php-errors.log']], $result->changes);
        self::assertSame([], $result->warnings);
    }

    public function testPathsInsideTheShopAreLeftAlone(): void
    {
        $ini = "error_log = /var/www/html/var/logs/php-errors.log\n";

        $result = (new PhpIniRewriter(self::directives(), $this->shop(), '/var/www/html'))->rewrite($ini);

        self::assertSame($ini, $result->content);
        self::assertSame([], $result->changes);
    }

    public function testQuotesSpacingAndTrailingCharactersSurvive(): void
    {
        $root = $this->shop();
        $ini = "  upload_tmp_dir  =  \"/old/htdocs/upload\" ; comment\nsession.save_path='/old/x/var/sessions'\n";

        $result = (new PhpIniRewriter(self::directives(), $root, '/srv/shop', ['var/sessions']))->rewrite($ini);

        self::assertSame("  upload_tmp_dir  =  \"/srv/shop/upload\" ; comment\nsession.save_path='/srv/shop/var/sessions'\n", $result->content);
    }

    public function testTheLongestExistingTailWins(): void
    {
        $root = $this->tempDirectory();
        mkdir($root . '/var/logs', 0777, true);
        mkdir($root . '/logs', 0777, true);

        $result = (new PhpIniRewriter(self::directives(), $root, '/new'))->rewrite("error_log = /old/var/logs/e.log\n");

        self::assertSame("error_log = /new/var/logs/e.log\n", $result->content, 'var/logs/e.log is a longer match than logs/e.log.');
    }

    public function testDirectoriesTheSkeletonStepWillCreateCount(): void
    {
        $root = $this->tempDirectory();

        $result = (new PhpIniRewriter(self::directives(), $root, '/new', ['var/logs']))->rewrite("error_log = /old/var/logs/e.log\n");

        self::assertSame("error_log = /new/var/logs/e.log\n", $result->content);
    }

    public function testPathWithoutAMatchingTailIsReported(): void
    {
        $root = $this->tempDirectory();

        $result = (new PhpIniRewriter(self::directives(), $root, '/new'))->rewrite("error_log = /somewhere/else/e.log\n");

        self::assertSame("error_log = /somewhere/else/e.log\n", $result->content);
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('error_log', $result->warnings[0]);
    }

    public function testOtherDirectivesAndRelativeValuesAreIgnored(): void
    {
        $ini = "memory_limit = 512M\nerror_log = php-errors.log\ndate.timezone = Europe/Berlin\n; error_log = /commented/out\n";

        $result = (new PhpIniRewriter(self::directives(), $this->shop(), '/new'))->rewrite($ini);

        self::assertSame($ini, $result->content);
    }
}
