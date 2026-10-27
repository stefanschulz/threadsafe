<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Profile;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\SitePath;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The site path of a profile: "not a readable directory" says which of the reasons it is.
 */
final class SitePathTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    public function testADirectoryIsResolved(): void
    {
        $base = $this->tempDirectory();

        self::assertSame(realpath($base), SitePath::resolve($base));
        self::assertNull(SitePath::problem($base));
    }

    public function testAPathOutsideOpenBasedirIsNamedAsThatAndNeverTouched(): void
    {
        $problem = SitePath::problem('/srv/other/site', '/home/u/web/site:/tmp');

        self::assertNotNull($problem);
        self::assertStringContainsString('open_basedir: /home/u/web/site, /tmp', $problem);
        self::assertStringContainsString('ask the host', $problem);
    }

    public function testAPathInsideOpenBasedirIsNotBlockedByIt(): void
    {
        $base = $this->tempDirectory();

        self::assertNull(SitePath::problem($base, $base));
        self::assertNull(SitePath::problem($base, '/nowhere:' . dirname($base)));
    }

    public function testAMissingDirectoryAndAFileAreToldApart(): void
    {
        $base = $this->tempDirectory();
        file_put_contents($base . '/file', 'x');

        self::assertStringContainsString('no such directory', (string) SitePath::problem($base . '/missing'));
        self::assertStringContainsString('a file, not a directory', (string) SitePath::problem($base . '/file'));
    }

    public function testTheMessageKeepsTheWordsTheProfilePagesAndTestsKnow(): void
    {
        $base = $this->tempDirectory();

        try {
            SitePath::resolve($base . '/missing');
            self::fail('expected an exception');
        } catch (ProfileException $e) {
            self::assertStringContainsString('is not a readable directory: there is no such directory', $e->getMessage());
        }
    }

    public function testAnEmptyPathIsRefused(): void
    {
        self::assertSame('the path is empty.', SitePath::problem(''));
    }
}
