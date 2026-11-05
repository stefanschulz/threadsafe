<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\BackupWorkspace;

/**
 * The directory the backup tool keeps its data and its archive in: closed to the web, and with a name that cannot be guessed.
 */
final class BackupWorkspaceTest extends TestCase
{
    use TempDirectory;

    private string $base;

    protected function setUp(): void
    {
        $this->base = $this->tempDirectory();
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    public function testNothingIsMadeUntilItIsAskedFor(): void
    {
        $workspace = BackupWorkspace::in($this->base);

        self::assertSame($this->base . '/.threadsafe-backup', $workspace->directory());
        self::assertNull($workspace->existingStorage());
        self::assertDirectoryDoesNotExist($workspace->directory());
    }

    public function testTheDataDirectoryIsClosedToTheWebAndItsNameCannotBeGuessed(): void
    {
        $workspace = BackupWorkspace::in($this->base);
        self::assertNull($workspace->existingData());
        $data = $workspace->data();

        self::assertMatchesRegularExpression('~/\.threadsafe-backup/data-[0-9a-f]{32}$~', $data, 'the profile (a database password) and the runs (a passphrase) are not at a fixed address');
        self::assertSame($data, BackupWorkspace::in($this->base)->data(), 'one data directory per tool: a second request finds it');
        self::assertSame($data, $workspace->existingData());
        foreach ([$workspace->directory(), $data] as $directory) {
            self::assertSame(0700, fileperms($directory) & 0777, $directory);
            self::assertFileExists($directory . '/.htaccess');
            self::assertFileExists($directory . '/index.html');
        }
        self::assertNotSame(basename($data), basename(BackupWorkspace::in($this->tempDirectory())->data()));
    }

    public function testTheStorageNameHas128RandomBitsAndStaysTheSame(): void
    {
        $first = BackupWorkspace::in($this->base)->storage();
        $again = BackupWorkspace::in($this->base)->storage();

        self::assertSame($first, $again, 'one storage per tool: a second call, even from a new request, finds it');
        self::assertMatchesRegularExpression('~/\.threadsafe-backup/storage-[0-9a-f]{32}$~', $first);
        self::assertSame($first, BackupWorkspace::in($this->base)->existingStorage());
        self::assertSame(0700, fileperms($first) & 0777);
        self::assertFileExists($first . '/.htaccess', 'closed to the web even where the name is not enough');
        self::assertFileExists($first . '/index.html');
    }

    public function testTwoToolsHaveDifferentStorageNames(): void
    {
        $other = $this->tempDirectory();

        self::assertNotSame(basename(BackupWorkspace::in($this->base)->storage()), basename(BackupWorkspace::in($other)->storage()));
    }

    public function testADirectoryThatLooksLikeAStorageButIsNotOneIsIgnored(): void
    {
        $workspace = BackupWorkspace::in($this->base);
        $workspace->data();
        mkdir($workspace->directory() . '/storage-short');
        mkdir($workspace->directory() . '/storage-' . str_repeat('A', 32));

        self::assertNull($workspace->existingStorage(), 'only 32 lower-case hex digits count');
        self::assertMatchesRegularExpression('~storage-[0-9a-f]{32}$~', $workspace->storage());
        self::assertTrue(\ThreadSafe\Profile\ConnectionTest::isToolStorage($workspace->storage()), 'the connection test knows the directory of the tool (no warning about a storage inside the site)');
    }

    public function testTheProfileAndTheRunsLiveInTheDataDirectoryLikeThoseOfTheApp(): void
    {
        $workspace = BackupWorkspace::in($this->base);

        self::assertSame([], $workspace->profiles()->ids());
        self::assertDirectoryExists($workspace->data());
        self::assertSame([], $workspace->runs()->all('backup'));
    }
}
