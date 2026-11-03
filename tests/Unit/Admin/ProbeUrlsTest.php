<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Admin\SystemController;

/**
 * The addresses where the System page asks the browser whether the data directory can be fetched from outside.
 */
final class ProbeUrlsTest extends TestCase
{
    public function testAnInstallationAtTheTopOfTheSiteAsksBelowTheProgramAndAbove(): void
    {
        self::assertSame(['/data/probe.txt'], SystemController::probeUrls('', null, '/var/www/html'));
    }

    public function testAnInstallationInADirectoryAsksAtTheDirectoryAndAboveIt(): void
    {
        self::assertSame(['/data/probe.txt', '/ts/data/probe.txt'], SystemController::probeUrls('/ts', null, '/var/www/html/ts'));
    }

    public function testAHomeNextToTheDocumentRootAddsNothing(): void
    {
        self::assertSame(['/data/probe.txt'], SystemController::probeUrls('', '/home/u/web/site/private/threadsafe', '/home/u/web/site/public_html'));
    }

    public function testAHomeBelowTheStubIsAskedWhereItReallyIs(): void
    {
        $urls = SystemController::probeUrls('', '/home/u/web/site/public_html/threadsafe-home', '/home/u/web/site/public_html');

        self::assertSame(['/data/probe.txt', '/threadsafe-home/data/probe.txt'], $urls, 'the weaker layout is checked where the data really is');
    }

    public function testAHomeBelowTheStubInASubDirectoryOfTheSite(): void
    {
        $urls = SystemController::probeUrls('/backup', '/srv/www/backup/threadsafe-home', '/srv/www/backup');

        self::assertContains('/backup/threadsafe-home/data/probe.txt', $urls);
    }

    public function testADirectoryWhoseNameOnlyStartsLikeTheStubDirectoryIsNotBelowIt(): void
    {
        self::assertSame(['/data/probe.txt'], SystemController::probeUrls('', '/srv/www2/threadsafe-home', '/srv/www'));
    }

    // ------------------------------------------------------------------------------------------------- the storages

    public function testAStorageBelowTheWebRootIsAskedAtItsOwnAddress(): void
    {
        $root = '/srv/www/html';

        self::assertSame(
            ['/threadsafe-home/backups/shop/probe.txt', '/a%20b/probe.txt'],
            SystemController::storageProbeUrls('', $root, [$root . '/threadsafe-home/backups/shop', $root . '/a b']),
        );
    }

    public function testAStorageInASubDirectoryOfTheSiteCarriesTheBasePath(): void
    {
        self::assertSame(['/backup/storage/probe.txt'], SystemController::storageProbeUrls('/backup', '/srv/www/backup', ['/srv/www/backup/storage']));
    }

    public function testAStorageOutsideTheDirectoryOrWithALikeNameIsNotAsked(): void
    {
        self::assertSame([], SystemController::storageProbeUrls('', '/srv/www', ['/srv/www2/storage', '/somewhere/else', '/srv']));
        self::assertSame([], SystemController::storageProbeUrls('', '/srv/www', []));
    }
}