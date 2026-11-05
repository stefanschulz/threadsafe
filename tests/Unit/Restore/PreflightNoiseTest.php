<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Restore;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Profile\ConnectionTest;
use ThreadSafe\Restore\Preflight;

/**
 * Field test: a backup made with threadsafe-backup.php before 0.10.0-beta.3 says "The storage directory is inside the site ..." in its report,
 * and the restore showed it as a warning although the tool keeps its archive there on purpose.
 */
final class PreflightNoiseTest extends TestCase
{
    public function testTheStorageWarningOfTheBackupToolIsNotShownAtTheRestore(): void
    {
        self::assertTrue(Preflight::isOldNoise('Storage: ' . ConnectionTest::STORAGE_INSIDE_SITE, 'site'));
    }

    public function testTheSameWarningOfAnotherProfileAndOtherWarningsAreShown(): void
    {
        self::assertFalse(Preflight::isOldNoise('Storage: ' . ConnectionTest::STORAGE_INSIDE_SITE, 'shop'));
        self::assertFalse(Preflight::isOldNoise('Storage: free space is low', 'site'));
        self::assertFalse(Preflight::isOldNoise('Database objects: a view was left out', 'site'));
    }
}
