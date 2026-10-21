<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Restore;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Restore\ExtractException;
use ThreadSafe\Restore\Extractor;
use ThreadSafe\Tests\Support\RawZip;
use ThreadSafe\Tests\Support\TempDirectory;

final class ExtractorTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    public function testFilesAndDatabaseFilesGoWhereTheyBelong(): void
    {
        [$root, $database] = $this->targets();
        $part = $this->part('p.zip', $this->standard());

        $state = $this->extractAll(['parts' => [$part], 'root' => $root, 'database' => $database]);

        self::assertSame('<?php echo 1;', file_get_contents($root . '/index.php'));
        self::assertSame('deep', file_get_contents($root . '/a/b/deep.txt'));
        self::assertSame(str_repeat('x', 1000), file_get_contents($root . '/media/big.bin'));
        self::assertDirectoryExists($root . '/empty');
        self::assertSame("CREATE TABLE t (id int);\n", file_get_contents($database . '/0001_t.sql'));
        self::assertFileDoesNotExist($database . '/threadsafe.json');
        self::assertFileDoesNotExist($root . '/threadsafe.json');
        self::assertFileDoesNotExist($root . '/db');
        self::assertSame(0644, fileperms($root . '/index.php') & 0777);
        self::assertSame(0600, fileperms($root . '/secret.txt') & 0777);
        self::assertSame(0755, fileperms($root . '/empty') & 0777);
        self::assertSame(4, $state['files'], 'four files below files/');
        self::assertSame(1, $state['databaseFiles'], 'and the SQL file');
        self::assertSame(2, $state['directories']);
        self::assertSame(1, $state['ignored'], 'the entry that is neither files/ nor db/');
        self::assertSame($state['total'], $state['bytes']);
    }

    public function testTheModificationTimeIsRestored(): void
    {
        [$root, $database] = $this->targets();
        $part = $this->part('p.zip', [
            ['name' => 'files/old.txt', 'data' => 'x', 'centralExtra' => pack('vvCV', 0x5455, 5, 1, 1700000000)],
        ]);

        $this->extractAll(['parts' => [$part], 'root' => $root, 'database' => $database]);

        self::assertSame(1700000000, filemtime($root . '/old.txt'));
    }

    public function testATickPerEntryGivesTheSameResultAcrossParts(): void
    {
        [$root, $database] = $this->targets();
        $one = $this->part('p1.zip', $this->standard());
        $two = $this->part('p2.zip', [
            ['name' => 'files/second/part.txt', 'data' => 'part two'],
            ['name' => 'files/second/more.txt', 'data' => 'more'],
            ['name' => 'db/0002_u.sql', 'data' => "CREATE TABLE u (id int);\n"],
        ]);

        $state = $this->extractAll(['parts' => [$one, $two], 'root' => $root, 'database' => $database], tickPerEntry: true);

        self::assertGreaterThan(8, $state['ticks']);
        self::assertSame('part two', file_get_contents($root . '/second/part.txt'));
        self::assertSame('more', file_get_contents($root . '/second/more.txt'));
        self::assertSame('<?php echo 1;', file_get_contents($root . '/index.php'));
        self::assertFileExists($database . '/0002_u.sql');
        self::assertSame(6, $state['files']);
        self::assertSame(2, $state['databaseFiles']);
    }

    public function testABigStoredFileContinuesAtTheByteItReached(): void
    {
        [$root, $database] = $this->targets();
        $big = random_bytes(200000);
        $part = $this->part('p.zip', [['name' => 'files/big.bin', 'data' => $big, 'method' => 0], ['name' => 'files/after.txt', 'data' => 'after']]);

        $state = $this->extractAll(['parts' => [$part], 'root' => $root, 'database' => $database], tickPerEntry: true);

        self::assertGreaterThan(5, $state['ticks'], 'the file needs many ticks of 16 KB');
        self::assertSame($big, file_get_contents($root . '/big.bin'));
        self::assertSame('after', file_get_contents($root . '/after.txt'));
    }

    public function testWhatAKilledProcessLeftBehindIsCutOff(): void
    {
        [$root, $database] = $this->targets();
        $big = random_bytes(100000);
        $part = $this->part('p.zip', [['name' => 'files/big.bin', 'data' => $big, 'method' => 0]]);
        $extractor = new Extractor();
        $state = $extractor->initialState(['parts' => [$part], 'root' => $root, 'database' => $database]);
        $state = $extractor->tick($state, new Budget(0.0))->state;
        self::assertGreaterThan(0, $state['current']);
        // the process that wrote one more chunk died before it saved its state
        file_put_contents($root . '/big.bin', 'JUNK BEYOND THE SAVED LENGTH', FILE_APPEND);

        $state = $this->resume($state);

        self::assertSame($big, file_get_contents($root . '/big.bin'));
        self::assertNull($state['current']);
    }

    public function testADamagedChecksumStopsTheRestoreAndNamesTheFile(): void
    {
        [$root, $database] = $this->targets();
        $part = $this->part('p.zip', [['name' => 'files/bad.txt', 'data' => 'content', 'crc' => 12345]]);

        $this->expectException(ExtractException::class);
        $this->expectExceptionMessage('"files/bad.txt"');
        try {
            $this->extractAll(['parts' => [$part], 'root' => $root, 'database' => $database]);
        } catch (ExtractException $e) {
            self::assertStringContainsString('CRC32', $e->getMessage());
            self::assertStringContainsString('partial state', $e->getMessage());

            throw $e;
        }
    }

    public function testADamagedChecksumOfAStoredFileInSeveralTicksIsFound(): void
    {
        [$root, $database] = $this->targets();
        $part = $this->part('p.zip', [['name' => 'files/bad.bin', 'data' => random_bytes(70000), 'method' => 0, 'crc' => 5]]);

        $this->expectException(ExtractException::class);
        $this->expectExceptionMessage('CRC32');
        $this->extractAll(['parts' => [$part], 'root' => $root, 'database' => $database], tickPerEntry: true);
    }

    /** @return iterable<string, array{string}> */
    public static function hostileNames(): iterable
    {
        yield 'parent directory' => ['files/../../outside.txt'];
        yield 'absolute' => ['/etc/outside.txt'];
        yield 'backslash' => ['files/..\\outside.txt'];
        yield 'drive letter' => ['C:/outside.txt'];
        yield 'empty segment' => ['files//outside.txt'];
    }

    #[DataProvider('hostileNames')]
    public function testHostileNamesWriteNothing(string $name): void
    {
        [$root, $database] = $this->targets();
        $part = $this->part('p.zip', [['name' => $name, 'data' => 'evil']]);

        try {
            $this->extractAll(['parts' => [$part], 'root' => $root, 'database' => $database]);
            self::fail('The name should have been refused.');
        } catch (ExtractException) {
            self::assertSame([], glob(dirname($root) . '/outside*') ?: []);
            self::assertFileDoesNotExist('/etc/outside.txt');
            self::assertSame([], array_diff(scandir($root) ?: [], ['.', '..']));
        }
    }

    public function testASymbolicLinkInTheRootIsNeverFollowed(): void
    {
        [$root, $database] = $this->targets();
        $outside = $this->tempDirectory();
        if (!@symlink($outside, $root . '/link')) {
            self::markTestSkipped('Symbolic links cannot be created here.');
        }
        $part = $this->part('p.zip', [['name' => 'files/link/planted.txt', 'data' => 'evil']]);

        try {
            $this->extractAll(['parts' => [$part], 'root' => $root, 'database' => $database]);
            self::fail('Writing through the link should have been refused.');
        } catch (ExtractException $e) {
            self::assertStringContainsString('symbolic link', $e->getMessage());
        }
        self::assertSame([], array_diff(scandir($outside) ?: [], ['.', '..']));
    }

    public function testALinkWhereAFileBelongsIsNotFollowed(): void
    {
        [$root, $database] = $this->targets();
        $outside = $this->tempDirectory() . '/target.txt';
        file_put_contents($outside, 'untouched');
        if (!@symlink($outside, $root . '/index.php')) {
            self::markTestSkipped('Symbolic links cannot be created here.');
        }
        $part = $this->part('p.zip', [['name' => 'files/index.php', 'data' => 'evil']]);

        $this->expectException(ExtractException::class);
        $this->expectExceptionMessage('symbolic link');
        try {
            $this->extractAll(['parts' => [$part], 'root' => $root, 'database' => $database]);
        } finally {
            self::assertSame('untouched', file_get_contents($outside));
        }
    }

    public function testADirectoryWhereAFileBelongsIsAnError(): void
    {
        [$root, $database] = $this->targets();
        mkdir($root . '/index.php');
        $part = $this->part('p.zip', [['name' => 'files/index.php', 'data' => 'x']]);

        $this->expectException(ExtractException::class);
        $this->expectExceptionMessage('a directory');
        $this->extractAll(['parts' => [$part], 'root' => $root, 'database' => $database]);
    }

    public function testAFileWhereADirectoryBelongsIsAnError(): void
    {
        [$root, $database] = $this->targets();
        file_put_contents($root . '/a', 'a file');
        $part = $this->part('p.zip', [['name' => 'files/a/b.txt', 'data' => 'x']]);

        $this->expectException(ExtractException::class);
        $this->expectExceptionMessage('is a file');
        $this->extractAll(['parts' => [$part], 'root' => $root, 'database' => $database]);
    }

    public function testTheToolsOwnFilesAreNeverOverwritten(): void
    {
        [$root, $database] = $this->targets();
        file_put_contents($root . '/restore.php', 'the tool');
        mkdir($root . '/.threadsafe-restore');
        file_put_contents($root . '/.threadsafe-restore/state.json', 'state');
        $part = $this->part('p.zip', [
            ['name' => 'files/restore.php', 'data' => 'from the backup'],
            ['name' => 'files/.threadsafe-restore/state.json', 'data' => 'evil'],
            ['name' => 'files/.threadsafe-restore/db/new.sql', 'data' => 'evil'],
            ['name' => 'files/restore.php.bak', 'data' => 'a different name'],
        ]);

        $state = $this->extractAll(['parts' => [$part], 'root' => $root, 'database' => $database, 'protected' => ['restore.php', '.threadsafe-restore']]);

        self::assertSame('the tool', file_get_contents($root . '/restore.php'));
        self::assertSame('state', file_get_contents($root . '/.threadsafe-restore/state.json'));
        self::assertFileDoesNotExist($root . '/.threadsafe-restore/db/new.sql');
        self::assertSame('a different name', file_get_contents($root . '/restore.php.bak'));
        self::assertCount(3, $state['warnings']);
        self::assertSame(3, $state['ignored']);
    }

    public function testExistingFilesAreReplacedEvenIfReadOnly(): void
    {
        [$root, $database] = $this->targets();
        file_put_contents($root . '/index.php', 'placeholder that is longer than the real file');
        chmod($root . '/index.php', 0444);
        $part = $this->part('p.zip', [['name' => 'files/index.php', 'data' => 'real']]);

        $this->extractAll(['parts' => [$part], 'root' => $root, 'database' => $database]);

        self::assertSame('real', file_get_contents($root . '/index.php'));
    }

    public function testSetuidBitsAreNotRestored(): void
    {
        [$root, $database] = $this->targets();
        $part = $this->part('p.zip', [['name' => 'files/tool', 'data' => 'x', 'external' => 0104755 << 16]]);

        $this->extractAll(['parts' => [$part], 'root' => $root, 'database' => $database]);

        self::assertSame(0755, fileperms($root . '/tool') & 07777);
    }

    public function testAMissingOrUnreadablePartIsRefusedUpFront(): void
    {
        [$root, $database] = $this->targets();
        $notZip = $this->tempDirectory() . '/p.zip';
        file_put_contents($notZip, 'this is not a zip file at all, it is just text');

        $this->expectException(ExtractException::class);
        (new Extractor())->initialState(['parts' => [$notZip], 'root' => $root, 'database' => $database]);
    }

    public function testNoPartsIsAnError(): void
    {
        $this->expectException(ExtractException::class);
        (new Extractor())->initialState(['parts' => [], 'root' => '/x', 'database' => '/y']);
    }

    public function testAnEmptyArchiveFinishesAtOnce(): void
    {
        [$root, $database] = $this->targets();
        $part = $this->part('p.zip', []);

        $state = $this->extractAll(['parts' => [$part], 'root' => $root, 'database' => $database]);

        self::assertSame(0, $state['files']);
        self::assertSame(1, $state['ticks']);
    }

    // ----------------------------------------------------------------------------------------------------- helpers

    /** @return list<array<string, mixed>> */
    private function standard(): array
    {
        return [
            ['name' => 'threadsafe-part.json', 'data' => '{}'],
            ['name' => 'files/', 'data' => '', 'method' => 0, 'external' => (040755 << 16) | 0x10],
            ['name' => 'files/index.php', 'data' => '<?php echo 1;'],
            ['name' => 'files/a/', 'data' => '', 'method' => 0, 'external' => (040700 << 16) | 0x10],
            ['name' => 'files/a/b/deep.txt', 'data' => 'deep'],
            ['name' => 'files/media/big.bin', 'data' => str_repeat('x', 1000), 'method' => 0],
            ['name' => 'files/empty/', 'data' => '', 'method' => 0, 'external' => (040755 << 16) | 0x10],
            ['name' => 'files/secret.txt', 'data' => 'secret', 'external' => 0100600 << 16],
            ['name' => 'db/0001_t.sql', 'data' => "CREATE TABLE t (id int);\n"],
            ['name' => 'something-else.txt', 'data' => 'ignored'],
            ['name' => 'threadsafe.json', 'data' => '{}'],
        ];
    }

    /** @return array{string, string} root, directory for SQL files */
    private function targets(): array
    {
        $base = $this->tempDirectory();
        mkdir($base . '/root');

        return [$base . '/root', $base . '/work/db'];
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function part(string $name, array $entries): string
    {
        $path = $this->tempDirectory() . '/' . $name;
        file_put_contents($path, RawZip::build($entries));

        return $path;
    }

    /**
     * Runs the extraction to its end; every tick as a new object, the state through JSON.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed> the final state, plus `ticks`
     */
    private function extractAll(array $parameters, bool $tickPerEntry = false): array
    {
        $state = (new Extractor())->initialState($parameters);

        return $this->resume($state, $tickPerEntry);
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function resume(array $state, bool $tickPerEntry = false): array
    {
        for ($tick = 1; $tick <= 5000; ++$tick) {
            $outcome = (new Extractor())->tick(
                json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
                $tickPerEntry ? new Budget(0.0) : Budget::unlimited(),
            );
            $state = $outcome->state;
            if ($outcome->finished) {
                return [...$state, 'ticks' => $tick];
            }
        }

        self::fail('The extraction did not finish.');
    }
}
