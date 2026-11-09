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

    // ------------------------------------------------------------------------------------------------- a workspace elsewhere (docs/PHASE13.md 9.2)

    public function testAWorkspaceAtAPlaceOfItsOwnIsCustomAndKeepsTheLayout(): void
    {
        $workspace = BackupWorkspace::at($this->base . '/private/ws/');

        self::assertTrue($workspace->isCustom());
        self::assertFalse(BackupWorkspace::in($this->base)->isCustom());
        self::assertSame($this->base . '/private/ws', $workspace->directory(), 'no trailing slash');
        mkdir($this->base . '/private');
        self::assertMatchesRegularExpression('~/private/ws/data-[0-9a-f]{32}$~', $workspace->data());
        self::assertMatchesRegularExpression('~/private/ws/storage-[0-9a-f]{32}$~', $workspace->storage());
        self::assertFileExists($workspace->directory() . '/.htaccess', 'closed to the web like the one next to the tool');
    }

    public function testAPathThatCouldBeTheWorkspaceOfAToolPasses(): void
    {
        $tool = $this->base . '/wp-content/plugins/threadsafe-connector';
        mkdir($tool, 0777, true);

        self::assertNull(BackupWorkspace::customProblem($this->base . '/wp-content/uploads/threadsafe-abc', $tool), 'not made yet is fine');
        self::assertNull(BackupWorkspace::customProblem($this->base . '/wp-content/plugins/threadsafe-connector-data', $tool), 'a sibling with a similar name is not inside');
        self::assertNull(BackupWorkspace::customProblem(dirname($this->base) . '/outside-the-site', $tool));
    }

    /**
     * @return array<string, array{string, string}> the path (%s is the temporary directory), a word of the message
     */
    public static function badWorkspaces(): array
    {
        return [
            'empty' => ['', 'empty'],
            'a stream wrapper' => ['phar:///x/y.phar/dir', 'stream wrapper'],
            'php://' => ['php://memory', 'stream wrapper'],
            'relative' => ['data/workspace', 'not absolute'],
            'with ..' => ['%s/wp-content/uploads/../../escape', '".."'],
            'inside the directory of the tool' => ['%s/wp-content/plugins/threadsafe-connector/data', 'inside the directory of the tool'],
            'the directory of the tool itself' => ['%s/wp-content/plugins/threadsafe-connector', 'inside the directory of the tool'],
            'around the tool' => ['%s/wp-content', 'lies inside it'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badWorkspaces')]
    public function testAPathThatCannotBeTheWorkspaceIsRefusedWithAReason(string $path, string $word): void
    {
        $tool = $this->base . '/wp-content/plugins/threadsafe-connector';
        mkdir($tool, 0777, true);

        $problem = BackupWorkspace::customProblem(sprintf($path, $this->base), $tool);

        self::assertNotNull($problem, $path);
        self::assertStringContainsString($word, $problem);
    }

    public function testALinkIntoTheDirectoryOfTheToolIsSeenThroughWhenTheLinkCanBeMade(): void
    {
        $tool = $this->base . '/plugin';
        mkdir($tool);
        $link = $this->base . '/link';
        if (!@symlink($tool, $link)) {
            self::markTestSkipped('This system cannot make symbolic links.');
        }

        self::assertStringContainsString('inside the directory of the tool', (string) BackupWorkspace::customProblem($link . '/ws', $tool));
    }

    public function testTheResolvedPathHasForwardSlashesNoTrailingOneAndTheRestOfAMissingPathAppended(): void
    {
        mkdir($this->base . '/real');

        self::assertSame(str_replace(chr(92), '/', (string) realpath($this->base . '/real')) . '/not/yet', BackupWorkspace::resolved($this->base . '/real/not/yet/'));
    }
}
