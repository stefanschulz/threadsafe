<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Backup;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Backup\Filters;
use ThreadSafe\Tests\Support\TempDirectory;

final class FiltersTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /** @return iterable<string, array{string, string, bool, bool}> pattern, path, is directory, excluded */
    public static function cases(): iterable
    {
        // a path from the root
        yield 'path: itself' => ['var/cache', 'var/cache', true, true];
        yield 'path: not a prefix match' => ['var/cache', 'var/cache2', true, false];
        yield 'path: not deeper in the tree' => ['var/cache', 'app/var/cache', true, false];
        yield 'path: other directory' => ['var/cache', 'var/logs', true, false];
        // a name at any depth
        yield 'name: top level' => ['*.log', 'error.log', false, true];
        yield 'name: deep' => ['*.log', 'var/logs/deep/error.log', false, true];
        yield 'name: other extension' => ['*.log', 'error.log.txt', false, false];
        yield 'name: exact name' => ['.DS_Store', 'img/.DS_Store', false, true];
        yield 'name: directory too' => ['node_modules', 'a/b/node_modules', true, true];
        // ** and *
        yield 'any depth: top' => ['**/.git', '.git', true, true];
        yield 'any depth: deep' => ['**/.git', 'modules/x/.git', true, true];
        yield 'any depth: not a part of a name' => ['**/.git', 'modules/x/not.git', true, false];
        yield 'star stays in one segment' => ['modules/*/cache', 'modules/ps_x/cache', true, true];
        yield 'star does not cross' => ['modules/*/cache', 'modules/ps_x/y/cache', true, false];
        yield 'double star crosses' => ['modules/**/cache', 'modules/ps_x/y/cache', true, true];
        yield 'question mark' => ['img/tmp?', 'img/tmp1', true, true];
        yield 'question mark needs a character' => ['img/tmp?', 'img/tmp', true, false];
        // directories only
        yield 'trailing slash: directory' => ['cache/', 'cache', true, true];
        yield 'trailing slash: a file of that name' => ['cache/', 'cache', false, false];
        // special characters in names are literal
        yield 'dot is literal' => ['a.b', 'axb', false, false];
        yield 'plus and brackets are literal' => ['a+b[1]', 'a+b[1]', false, true];
        yield 'unicode' => ['ümläut/*', 'ümläut/x', false, true];
    }

    #[DataProvider('cases')]
    public function testPatterns(string $pattern, string $path, bool $isDirectory, bool $excluded): void
    {
        self::assertSame($excluded, (new Filters([$pattern]))->excludes($path, $isDirectory));
    }

    public function testSeveralPatternsAndNoPatterns(): void
    {
        $filters = new Filters(['var/cache', '*.log']);

        self::assertTrue($filters->excludes('var/cache', true));
        self::assertTrue($filters->excludes('x.log', false));
        self::assertFalse($filters->excludes('x.php', false));
        self::assertFalse((new Filters())->excludes('anything', false));
    }

    public function testLiteralPathsAreExactWhateverTheyContain(): void
    {
        $filters = new Filters([], ['backups/[main] *.old', '/leading/and/trailing/']);

        self::assertTrue($filters->excludes('backups/[main] *.old', true));
        self::assertFalse($filters->excludes('backups/[main] x.old', true));
        self::assertTrue($filters->excludes('leading/and/trailing', true));
        self::assertFalse($filters->excludes('leading', true));
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'only a slash' => ['/'];
        yield 'absolute' => ['/var/cache'];
        yield 'parent' => ['../x'];
        yield 'parent inside' => ['a/../b'];
        yield 'dot segment' => ['a/./b'];
        yield 'empty segment' => ['a//b'];
        yield 'backslash' => ['a\\b'];
        yield 'NUL' => ["a\0b"];
    }

    #[DataProvider('invalid')]
    public function testInvalidPatternsAreRefused(string $pattern): void
    {
        self::assertNotNull(Filters::problem($pattern));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid filter');

        new Filters([$pattern]);
    }

    public function testRelativeInsideFindsDirectoriesOfTheSite(): void
    {
        $root = $this->tempDirectory();
        mkdir($root . '/var/backups', 0777, true);
        $outside = $this->tempDirectory();

        self::assertSame('var/backups', Filters::relativeInside($root, $root . '/var/backups'));
        self::assertSame('var/backups', Filters::relativeInside($root . '/', $root . '/var/../var/backups/'));
        self::assertNull(Filters::relativeInside($root, $root), 'The root itself is not "inside".');
        self::assertNull(Filters::relativeInside($root, $outside), 'A directory elsewhere is not excluded from the scan.');
        self::assertNull(Filters::relativeInside($root, $root . '/missing'));
    }
}
