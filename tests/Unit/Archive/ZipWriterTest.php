<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Archive;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ThreadSafe\Archive\ZipException;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Archive\ZipWriter;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Tests\Support\UnzipOracle;

final class ZipWriterTest extends TestCase
{
    use TempDirectory;

    private const int MTIME = 1_800_000_000; // 2027-01-15

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * Writes an entry from a string in blocks, the way the engine does with a file.
     */
    private function put(ZipWriter $writer, string $name, string $content, bool $compress = true, int $mtime = self::MTIME, int $mode = 0100644): void
    {
        $writer->beginFile($name, $mtime, $mode, strlen($content), $compress);
        $length = strlen($content);
        for ($position = 0; $length - $position >= ZipWriter::BLOCK; $position += ZipWriter::BLOCK) {
            $writer->writeBlock(substr($content, $position, ZipWriter::BLOCK));
        }
        $writer->endFile(substr($content, $position));
    }

    /** @return iterable<string, array{int}> */
    public static function sizes(): iterable
    {
        foreach ([0, 1, 100, ZipWriter::BLOCK - 1, ZipWriter::BLOCK, ZipWriter::BLOCK + 1, 3 * ZipWriter::BLOCK, 3 * ZipWriter::BLOCK + 7] as $size) {
            yield "$size bytes" => [$size];
        }
    }

    private static function content(int $size, bool $random): string
    {
        if ($random) {
            return $size < 1 ? '' : (new Randomizer(new Mt19937($size)))->getBytes($size);
        }

        return substr(str_repeat("The quick brown fox jumps over the lazy dog $size\n", intdiv($size, 40) + 2), 0, $size);
    }

    #[DataProvider('sizes')]
    public function testEntriesOfEverySizeReadBackAndPassUnzip(int $size): void
    {
        $path = $this->tempDirectory() . '/a.zip';
        $writer = ZipWriter::create($path);
        $this->put($writer, 'files/compressible.txt', self::content($size, false));
        $this->put($writer, 'files/random.bin', self::content($size, true));
        $this->put($writer, 'files/stored.jpg', self::content($size, true), compress: false);
        $info = $writer->close();

        self::assertSame(3, $info->entries);
        self::assertSame($path, $info->path);
        self::assertSame(filesize($path), $info->size);
        self::assertSame(hash_file('sha256', $path), $info->sha256, 'The SHA-256 of the part is computed while writing.');
        self::assertFileDoesNotExist($path . '.part');
        self::assertFileDoesNotExist($path . '.cd');

        $reader = ZipReader::open($path);
        self::assertTrue($reader->verify()->ok(), implode("\n", $reader->verify()->problems));
        foreach (['files/compressible.txt' => false, 'files/random.bin' => true, 'files/stored.jpg' => true] as $name => $random) {
            $entry = $reader->find($name);
            self::assertNotNull($entry, $name);
            self::assertSame($size, $entry->size);
            self::assertSame(self::content($size, $random), $reader->contents($entry), $name);
        }
        self::assertSame(0, $reader->find('files/stored.jpg')?->method, 'compress: false stores the data as it is.');
        self::assertSame(8, $reader->find('files/random.bin')?->method);

        if (UnzipOracle::available()) {
            [$ok, $output] = UnzipOracle::test($path);
            self::assertTrue($ok, $output);
        }
    }

    public function testMetadataNamesAndDirectories(): void
    {
        $path = $this->tempDirectory() . '/a.zip';
        $writer = ZipWriter::create($path);
        $writer->addDirectory('files/var/cache', self::MTIME, 040750);
        $this->put($writer, 'files/ümläut ✓ & space.txt', 'hello', mtime: 1_700_000_001, mode: 0100600);
        $writer->addString('threadsafe.json', '{"format":1}', self::MTIME);
        $writer->close();

        $reader = ZipReader::open($path);
        $entries = iterator_to_array($reader->entries(), false);
        self::assertSame(['files/var/cache/', 'files/ümläut ✓ & space.txt', 'threadsafe.json'], array_map(static fn ($e): string => $e->name, $entries));
        self::assertTrue($entries[0]->isDirectory);
        self::assertSame(040750, $entries[0]->mode);
        self::assertSame(0100600, $entries[1]->mode);
        self::assertSame(1_700_000_001, $entries[1]->mtime, 'The exact Unix time survives (the DOS field only has 2-second steps).');
        self::assertSame(0100644, $entries[2]->mode);
        self::assertSame('{"format":1}', $reader->contents($entries[2]));
        self::assertSame(0x0800, $entries[1]->flags & 0x0800, 'A non-ASCII name sets the UTF-8 flag.');
        self::assertSame(0, $entries[2]->flags & 0x0800);
        self::assertTrue($reader->verify()->ok());
        if (UnzipOracle::available()) {
            self::assertTrue(UnzipOracle::test($path)[0]);
            $target = $this->tempDirectory();
            self::assertTrue(UnzipOracle::extract($path, $target));
            self::assertSame('hello', file_get_contents($target . '/files/ümläut ✓ & space.txt'));
            self::assertDirectoryExists($target . '/files/var/cache');
        }
    }

    public function testForcedZip64RecordsAreReadAndAcceptedByUnzip(): void
    {
        $path = $this->tempDirectory() . '/z.zip';
        $writer = ZipWriter::create($path, forceZip64: true);
        $this->put($writer, 'a.txt', self::content(1000, false));
        $this->put($writer, 'b.bin', self::content(3 * ZipWriter::BLOCK + 5, true));
        $writer->addDirectory('d', self::MTIME);
        $writer->close();

        $reader = ZipReader::open($path);
        self::assertSame(3, $reader->count());
        self::assertTrue($reader->verify()->ok(), implode("\n", $reader->verify()->problems));
        self::assertSame(self::content(1000, false), $reader->contents($reader->find('a.txt') ?? throw new \LogicException()));
        $bytes = (string) file_get_contents($path);
        self::assertStringContainsString(pack('V', 0x06064b50), $bytes, 'A ZIP64 end record is there.');
        self::assertStringContainsString(pack('V', 0x07064b50), $bytes, 'And its locator.');
        if (UnzipOracle::available()) {
            [$ok, $output] = UnzipOracle::test($path);
            self::assertTrue($ok, $output);
        }
    }

    public function testEntriesOfUnknownSizeGetZip64RecordsAndStillPass(): void
    {
        $path = $this->tempDirectory() . '/u.zip';
        $writer = ZipWriter::create($path);
        $writer->beginFile('db/0001_t.sql', self::MTIME, 0100644, null);   // a dump: its size is not known beforehand
        $writer->writeBlock(self::content(ZipWriter::BLOCK, false));
        $writer->endFile('tail');
        $writer->close();

        $reader = ZipReader::open($path);
        self::assertTrue($reader->verify()->ok());
        self::assertSame(self::content(ZipWriter::BLOCK, false) . 'tail', $reader->contents($reader->find('db/0001_t.sql') ?? throw new \LogicException()));
        if (UnzipOracle::available()) {
            self::assertTrue(UnzipOracle::test($path)[0]);
        }
    }

    public function testEmptyPartIsAValidArchive(): void
    {
        $path = $this->tempDirectory() . '/e.zip';

        $info = ZipWriter::create($path)->close();

        self::assertSame(0, $info->entries);
        self::assertSame(22, $info->size);
        self::assertSame(0, ZipReader::open($path)->count());
    }

    public function testRolloverDecisions(): void
    {
        $writer = ZipWriter::create($this->tempDirectory() . '/r.zip');
        self::assertFalse($writer->needsRollover(10), 'An empty part never rolls over, however small the limit.');
        $this->put($writer, 'a', str_repeat('x', 100));
        $size = $writer->size();

        self::assertTrue($writer->needsRollover($size), 'A full part rolls over.');
        self::assertFalse($writer->needsRollover($size + 1000));
        self::assertTrue($writer->needsRollover($size + 1000, 2000), 'An entry that would not fit starts the next part.');
        self::assertFalse($writer->needsRollover($size + 1000, 500));
        $writer->beginFile('b', self::MTIME);
        self::assertFalse($writer->needsRollover(1), 'Never in the middle of an entry.');
        $writer->endFile();
        $writer->abort();
    }

    public function testPartsAreCompleteArchivesOnTheirOwn(): void
    {
        $directory = $this->tempDirectory();
        $parts = [];
        $writer = ZipWriter::create($directory . '/p-p01.zip');
        foreach ([1 => 400000, 2 => 400000, 3 => 400000, 4 => 10] as $i => $size) {
            if ($writer->needsRollover(500000, $size)) {
                $parts[] = $writer->close();
                $writer = ZipWriter::create($directory . sprintf('/p-p%02d.zip', count($parts) + 1));
            }
            $this->put($writer, "files/f$i", self::content($size, true), compress: false);
        }
        $parts[] = $writer->close();

        self::assertGreaterThanOrEqual(3, count($parts));
        $names = [];
        foreach ($parts as $part) {
            $reader = ZipReader::open($part->path);
            self::assertTrue($reader->verify()->ok());
            self::assertSame($part->sha256, hash_file('sha256', $part->path));
            foreach ($reader->entries() as $entry) {
                $names[] = $entry->name;
            }
        }
        self::assertSame(['files/f1', 'files/f2', 'files/f3', 'files/f4'], $names, 'Every file is in exactly one part.');
    }

    public function testMisuseIsRefused(): void
    {
        $path = $this->tempDirectory() . '/m.zip';
        $writer = ZipWriter::create($path);

        $this->expectOneOf(fn () => $writer->writeBlock(str_repeat('x', ZipWriter::BLOCK)), 'No entry is open');
        $this->expectOneOf(fn () => $writer->endFile(), 'No entry is open');
        $this->expectOneOf(fn () => $writer->beginFile('../evil', self::MTIME), 'path leaves its directory');
        $this->expectOneOf(fn () => $writer->beginFile('/abs', self::MTIME), 'absolute path');
        $this->expectOneOf(fn () => $writer->beginFile('dir/', self::MTIME), 'must not end with a slash');
        $this->expectOneOf(fn () => $writer->beginFile("a\0b", self::MTIME), 'NUL');
        $writer->beginFile('ok', self::MTIME);
        $this->expectOneOf(fn () => $writer->writeBlock('short'), 'exactly');
        $this->expectOneOf(fn () => $writer->beginFile('second', self::MTIME), 'still open');
        $this->expectOneOf(fn () => $writer->close(), 'still open');
        $this->expectOneOf(fn () => $writer->endFile(str_repeat('x', ZipWriter::BLOCK)), 'shorter');
        $writer->endFile('fine');
        $writer->close();

        $this->expectOneOf(fn () => ZipWriter::create($path), 'exists already');
        $this->expectOneOf(fn () => ZipWriter::create($this->tempDirectory() . '/l.zip', level: 10), 'between 1 and 9');
        $this->expectOneOf(fn () => $writer->addString('late', 'x', self::MTIME), 'closed');
    }

    private function expectOneOf(callable $action, string $messagePart): void
    {
        try {
            $action();
        } catch (ZipException $e) {
            self::assertStringContainsString($messagePart, $e->getMessage());

            return;
        }
        self::fail("Expected a ZipException containing \"$messagePart\".");
    }

    public function testAbortRemovesTheFiles(): void
    {
        $path = $this->tempDirectory() . '/x.zip';
        $writer = ZipWriter::create($path);
        $this->put($writer, 'a', 'content');
        self::assertFileExists($path . '.part');

        $writer->abort();

        self::assertFileDoesNotExist($path . '.part');
        self::assertFileDoesNotExist($path . '.cd');
        self::assertFileDoesNotExist($path);
    }

    public function testResumeRefusesDamagedState(): void
    {
        $path = $this->tempDirectory() . '/s.zip';
        $writer = ZipWriter::create($path);
        $this->put($writer, 'a', self::content(1000, true));
        $state = $writer->state();
        unset($writer);

        foreach ([
            ['version' => 99] + $state,
            ['sha' => 'not base64!'] + $state,
            ['offset' => $state['offset'] + 100000] + $state,
            ['directorySize' => $state['directorySize'] + 100000] + $state,
        ] as $broken) {
            try {
                ZipWriter::resume($broken);
                self::fail('A damaged state must be refused.');
            } catch (ZipException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        self::assertSame(1, ZipWriter::resume($state)->close()->entries);
    }

    /** @return iterable<string, array{int}> */
    public static function seeds(): iterable
    {
        for ($seed = 1; $seed <= 40; ++$seed) {
            yield "seed $seed" => [$seed];
        }
    }

    /**
     * The heart of the matter: however the work is cut into ticks - a new process (a new writer object from the saved state)
     * after any block, even with garbage left behind by a process that died after the save - the archive equals the one
     * written in a single run, byte for byte.
     */
    #[DataProvider('seeds')]
    public function testResumingAnywhereGivesTheSameBytes(int $seed): void
    {
        $random = new Randomizer(new Mt19937($seed));
        $entries = [];
        for ($i = 0, $count = $random->getInt(1, 6); $i < $count; ++$i) {
            $sizes = [0, 1, 1000, ZipWriter::BLOCK, ZipWriter::BLOCK + 1, 2 * ZipWriter::BLOCK - 1, 5 * ZipWriter::BLOCK + 3];
            $size = $sizes[$random->getInt(0, count($sizes) - 1)];
            $entries[] = [
                'name' => "files/dir$i/file$i-" . $random->getInt(0, 999) . ($random->getInt(0, 1) === 0 ? '.txt' : '.jpg'),
                'content' => $random->getInt(0, 1) === 0 && $size > 0 ? $random->getBytes($size) : substr(str_repeat("repeat me $seed\n", intdiv($size, 10) + 1), 0, $size),
                'compress' => $random->getInt(0, 3) !== 0,
            ];
        }

        $reference = $this->write($this->tempDirectory() . '/reference.zip', $entries, null);
        $cutPath = $this->tempDirectory() . '/cut.zip';
        $cut = $this->write($cutPath, $entries, new Randomizer(new Mt19937($seed + 1000)));

        self::assertSame(file_get_contents($reference->path), file_get_contents($cut->path), 'The archive must not depend on where the ticks ended.');
        self::assertSame($reference->sha256, $cut->sha256);
        self::assertTrue(ZipReader::open($cut->path)->verify()->ok());
    }

    /**
     * @param list<array{name: string, content: string, compress: bool}> $entries
     * @param Randomizer|null                                           $cuts    when given, the writer is replaced by a resumed one at random points
     */
    private function write(string $path, array $entries, ?Randomizer $cuts): \ThreadSafe\Archive\PartInfo
    {
        $writer = ZipWriter::create($path);
        $tick = function () use (&$writer, $cuts, $path): void {
            if ($cuts === null || $cuts->getInt(0, 2) !== 0) {
                return;
            }
            $state = $writer->state();
            // what a process that died after the save may have left behind
            file_put_contents($path . '.part', str_repeat('JUNK', 1000), FILE_APPEND);
            file_put_contents($path . '.cd', 'JUNK', FILE_APPEND);
            $writer = ZipWriter::resume($state);   // a "new process": the old object is only dropped, never used again
        };

        foreach ($entries as $entry) {
            $content = $entry['content'];
            $writer->beginFile($entry['name'], self::MTIME, 0100644, strlen($content), $entry['compress']);
            $tick();
            $length = strlen($content);
            for ($position = 0; $length - $position >= ZipWriter::BLOCK; $position += ZipWriter::BLOCK) {
                $writer->writeBlock(substr($content, $position, ZipWriter::BLOCK));
                $tick();
            }
            $writer->endFile(substr($content, $position));
            $tick();
        }
        $writer->addString('threadsafe.json', '{}', self::MTIME);

        return $writer->close();
    }
}
