<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Core\OpenBasedir;

/**
 * `open_basedir`: which directories PHP may use, and whether a path is among them.
 */
final class OpenBasedirTest extends TestCase
{
    private const string HOST = '/home/u/.composer:/home/u/web/site/public_html:/home/u/web/site/private:/home/u/tmp:/tmp:/usr/bin';

    public function testWithoutARestrictionEverythingIsPermitted(): void
    {
        self::assertSame([], OpenBasedir::allowed(''));
        self::assertTrue(OpenBasedir::permits('/anywhere/at/all', ''));
        self::assertFalse(OpenBasedir::restricted(''));
    }

    public function testTheAllowedDirectoriesAreListedWithoutTrailingSlashes(): void
    {
        self::assertSame(['/a', '/b/c'], OpenBasedir::allowed('/a/:/b/c'));
        self::assertTrue(OpenBasedir::restricted(self::HOST));
    }

    public function testRelativeAndEmptyEntriesAreLeftOut(): void
    {
        self::assertSame(['/a'], OpenBasedir::allowed(':relative/dir::/a:'));
    }

    public function testAPathIsPermittedIfItIsAnAllowedDirectoryOrBelowOne(): void
    {
        self::assertTrue(OpenBasedir::permits('/home/u/web/site/private', self::HOST));
        self::assertTrue(OpenBasedir::permits('/home/u/web/site/private/threadsafe/data', self::HOST));
        self::assertTrue(OpenBasedir::permits('/home/u/web/site/private/', self::HOST));
        self::assertTrue(OpenBasedir::permits('/tmp/x', self::HOST));
    }

    public function testAPathAboveOrBesideAnAllowedDirectoryIsNot(): void
    {
        foreach (['/home/u/web/site', '/home/u/web', '/home/u', '/home', '/', '/home/u/web/site/privateer', '/home/u/web/site/public_htmlx', '/etc'] as $path) {
            self::assertFalse(OpenBasedir::permits($path, self::HOST), $path);
        }
    }

    public function testTheDotMeansTheCurrentDirectory(): void
    {
        $here = rtrim(str_replace('\\', '/', (string) getcwd()), '/');

        self::assertSame([$here], OpenBasedir::allowed('.'));
        self::assertTrue(OpenBasedir::permits($here . '/sub', '.'));
    }
}
