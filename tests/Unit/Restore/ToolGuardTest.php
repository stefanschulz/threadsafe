<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Restore;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Restore\ToolGuard;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The block that keeps restore.php reachable while the `.htaccess` of the restored site is in place (field test: the Admin Tools rule "no direct
 * request for a PHP file" locked the tool out in the middle of the restore).
 */
final class ToolGuardTest extends TestCase
{
    use TempDirectory;

    private const string ADMIN_TOOLS = "RewriteEngine On\nRewriteCond %{REQUEST_FILENAME} (\\.php)\$\nRewriteRule (.*\\.php)\$ - [F]\n";

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function root(string $htaccess = self::ADMIN_TOOLS): string
    {
        $root = $this->tempDirectory();
        file_put_contents($root . '/.htaccess', $htaccess);

        return $root;
    }

    public function testTheBlockStandsAtTheTopSoThatItIsTheFirstRuleThatMatches(): void
    {
        $root = $this->root();

        self::assertTrue(ToolGuard::protect($root, ['restore.php']));

        $content = (string) file_get_contents($root . '/.htaccess');
        self::assertStringStartsWith(ToolGuard::BEGIN . "\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^restore\\.php\$ - [L]\n</IfModule>\n" . ToolGuard::END . "\n", $content);
        self::assertStringEndsWith(self::ADMIN_TOOLS, $content, 'what the site brought is untouched, below the block');
    }

    public function testItIsDoneOnceHoweverOftenItIsAsked(): void
    {
        $root = $this->root();
        ToolGuard::protect($root, ['restore.php']);
        $once = (string) file_get_contents($root . '/.htaccess');

        self::assertFalse(ToolGuard::protect($root, ['restore.php']), 'nothing to change');
        self::assertSame($once, (string) file_get_contents($root . '/.htaccess'));
        self::assertSame(1, substr_count($once, ToolGuard::BEGIN));
    }

    public function testAnotherNameRenewsTheBlockInsteadOfAddingOne(): void
    {
        $root = $this->root();
        ToolGuard::protect($root, ['restore.php']);

        self::assertTrue(ToolGuard::protect($root, ['recover.php', 'sub/restore.php']));

        $content = (string) file_get_contents($root . '/.htaccess');
        self::assertSame(1, substr_count($content, ToolGuard::BEGIN));
        self::assertStringContainsString('RewriteRule ^recover\\.php$ - [L]', $content);
        self::assertStringContainsString('RewriteRule ^sub/restore\\.php$ - [L]', $content);
        self::assertStringNotContainsString('^restore\\.php$', $content);
    }

    public function testTheCharactersOfARegularExpressionInANameAreWrittenLiterally(): void
    {
        $root = $this->root();

        ToolGuard::protect($root, ['re+store(1).php']);

        self::assertStringContainsString('RewriteRule ^re\\+store\\(1\\)\\.php$ - [L]', (string) file_get_contents($root . '/.htaccess'));
    }

    public function testReleasingGivesTheFileBackExactlyAsItCame(): void
    {
        foreach ([self::ADMIN_TOOLS, "# a site\r\nRewriteEngine On\r\n", ''] as $original) {
            $root = $this->root($original);
            ToolGuard::protect($root, ['restore.php']);

            self::assertTrue(ToolGuard::release($root));

            self::assertSame($original, (string) file_get_contents($root . '/.htaccess'));
        }
    }

    public function testWithoutAnHtaccessOrWithNothingToProtectNothingHappens(): void
    {
        $empty = $this->tempDirectory();

        self::assertFalse(ToolGuard::protect($empty, ['restore.php']));
        self::assertFileDoesNotExist($empty . '/.htaccess', 'no file is made: a site without one is not locked out');
        self::assertFalse(ToolGuard::release($empty));
        self::assertFalse(ToolGuard::protect($this->root(), []));
        self::assertFalse(ToolGuard::protect($this->root(), ['../escape.php', '']), 'names that lead out of the directory are no names');
        self::assertFalse(ToolGuard::release($this->root()), 'nothing to take out');
    }

    public function testTheModeOfTheFileStays(): void
    {
        $root = $this->root();
        chmod($root . '/.htaccess', 0444);

        ToolGuard::protect($root, ['restore.php']);

        self::assertSame(0444, fileperms($root . '/.htaccess') & 0777, 'a read-only .htaccess stays read-only');
    }
}
