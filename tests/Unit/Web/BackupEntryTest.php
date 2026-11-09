<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\BackupEntry;
use ThreadSafe\Web\BackupWorkspace;

/**
 * Which workspace the entry of the backup tool chooses (docs/PHASE13.md 9.2): next to the tool, unless a plugin defined THREADSAFE_WORKSPACE, and
 * then only if the path is one that the tool may use.
 */
final class BackupEntryTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    public function testWithoutTheConstantTheWorkspaceIsNextToTheTool(): void
    {
        self::assertNull(BackupEntry::workspace('/site/threadsafe-backup.php', false, null));
        self::assertNull(BackupEntry::workspace('/site/threadsafe-backup.php', false, '/ignored/when/not/defined'));
    }

    public function testAGoodPathIsTheWorkspaceAndIsCustom(): void
    {
        $base = $this->tempDirectory();
        mkdir($base . '/plugin');

        $workspace = BackupEntry::workspace($base . '/plugin/threadsafe-backup.php', true, $base . '/uploads/threadsafe-abc');

        self::assertInstanceOf(BackupWorkspace::class, $workspace);
        self::assertTrue($workspace->isCustom());
        self::assertSame($base . '/uploads/threadsafe-abc', $workspace->directory());
    }

    public function testABadPathIsReturnedAsTheReasonAndNeverUsed(): void
    {
        $base = $this->tempDirectory();
        mkdir($base . '/plugin');

        $inside = BackupEntry::workspace($base . '/plugin/threadsafe-backup.php', true, $base . '/plugin/data');
        $wrapper = BackupEntry::workspace($base . '/plugin/threadsafe-backup.php', true, 'phar:///x.phar');
        self::assertIsString($inside);
        self::assertIsString($wrapper);
        self::assertStringContainsString('inside the directory of the tool', $inside);
        self::assertStringContainsString('stream wrapper', $wrapper);
        self::assertSame('it is not a text', BackupEntry::workspace($base . '/plugin/threadsafe-backup.php', true, ['not', 'a', 'path']));
        self::assertSame('it is not a text', BackupEntry::workspace($base . '/plugin/threadsafe-backup.php', true, null));
    }
}