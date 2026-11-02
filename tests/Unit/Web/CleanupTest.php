<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Web;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\Cleanup;

/**
 * Taking a tool off the server: its files, its state directory, and what is to stay in it.
 */
final class CleanupTest extends TestCase
{
    use TempDirectory;

    private string $base;

    protected function setUp(): void
    {
        $this->base = $this->tempDirectory();
        mkdir($this->base . '/.state/data/sessions', 0777, true);
        mkdir($this->base . '/.state/storage-1', 0777, true);
        file_put_contents($this->base . '/.state/data/sessions/a', 'x');
        file_put_contents($this->base . '/.state/storage-1/backup.zip', 'archive');
        file_put_contents($this->base . '/.state/.htaccess', 'deny');
        file_put_contents($this->base . '/tool.php', '<?php');
        file_put_contents($this->base . '/key.php', '<?php');
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    public function testTheFilesAndTheWholeStateDirectoryGo(): void
    {
        $problems = Cleanup::run([$this->base . '/tool.php', $this->base . '/key.php'], $this->base . '/.state');

        self::assertSame([], $problems);
        self::assertFileDoesNotExist($this->base . '/tool.php');
        self::assertFileDoesNotExist($this->base . '/key.php');
        self::assertDirectoryDoesNotExist($this->base . '/.state');
    }

    public function testAPathThatIsKeptStaysAndTheRestOfTheStateDirectoryGoes(): void
    {
        $problems = Cleanup::run([$this->base . '/tool.php'], $this->base . '/.state', [$this->base . '/.state/storage-1']);

        self::assertSame([], $problems);
        self::assertFileExists($this->base . '/.state/storage-1/backup.zip');
        self::assertSame(['storage-1'], array_values(array_diff(scandir($this->base . '/.state') ?: [], ['.', '..'])));
    }

    public function testAKeptPathOutsideTheStateDirectoryChangesNothing(): void
    {
        mkdir($this->base . '/elsewhere');

        Cleanup::run([], $this->base . '/.state', [$this->base . '/elsewhere']);

        self::assertDirectoryDoesNotExist($this->base . '/.state');
        self::assertDirectoryExists($this->base . '/elsewhere');
    }

    public function testAMissingStateDirectoryIsNoProblem(): void
    {
        self::assertSame([], Cleanup::run([$this->base . '/nothing.php'], $this->base . '/no-state'));
    }
}
