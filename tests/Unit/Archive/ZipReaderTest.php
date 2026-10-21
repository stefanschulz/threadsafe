<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Archive;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Archive\ZipException;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Tests\Support\RawZip;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The reader against archives that are damaged or hostile. A name outside the target directory, a lying size or a bomb
 * must be refused with a clear message; nothing may be written, read without bound, or believed.
 */
final class ZipReaderTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function open(string $bytes): ZipReader
    {
        $path = $this->tempDirectory() . '/t.zip';
        file_put_contents($path, $bytes);

        return ZipReader::open($path);
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param array<string, mixed>       $options
     *
     * @return list<string> the problems verify() reports
     */
    private function problems(array $entries, array $options = []): array
    {
        return $this->open(RawZip::build($entries, $options))->verify()->problems;
    }

    public function testAPlainArchiveIsFine(): void
    {
        $reader = $this->open(RawZip::build([['name' => 'a.txt', 'data' => 'hello'], ['name' => 'dir/b.txt', 'data' => str_repeat('x', 5000), 'method' => 0]]));

        self::assertTrue($reader->verify()->ok());
        self::assertSame(2, $reader->count());
        self::assertSame('hello', $reader->contents($reader->find('a.txt') ?? throw new \LogicException()));
        self::assertNull($reader->find('missing'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function unsafeNames(): iterable
    {
        yield 'parent directory' => ['../evil.php', 'leaves its directory'];
        yield 'deep parent directory' => ['a/b/../../../evil', 'leaves its directory'];
        yield 'absolute path' => ['/etc/cron.d/evil', 'absolute path'];
        yield 'drive letter' => ['C:/Windows/evil.dll', 'drive letter'];
        yield 'backslash' => ['a\\..\\evil', 'backslash'];
        yield 'NUL' => ["ok.txt\0.php", 'NUL'];
        yield 'empty segment' => ['a//b', 'empty segments'];
        yield 'dot segment' => ['./a', 'leaves its directory'];
    }

    #[DataProvider('unsafeNames')]
    public function testUnsafeNamesAreRefusedForExtractionAndReportedByVerify(string $name, string $reason): void
    {
        $reader = $this->open(RawZip::build([['name' => $name, 'data' => 'payload']]));
        $entry = iterator_to_array($reader->entries(), false)[0];

        try {
            $reader->extract($entry, static function (string $chunk): void {
                self::fail('Nothing may be handed to the sink.');
            });
            self::fail('An unsafe name must be refused.');
        } catch (ZipException $e) {
            self::assertStringContainsString($reason, $e->getMessage());
        }
        self::assertCount(1, $reader->verify()->problems);
        self::assertStringContainsString($reason, $reader->verify()->problems[0]);
    }

    public function testNotAZipFile(): void
    {
        $path = $this->tempDirectory() . '/n.zip';
        file_put_contents($path, str_repeat('not a zip file. ', 100));
        $this->expectException(ZipException::class);
        $this->expectExceptionMessage('has no end record');

        ZipReader::open($path);
    }

    public function testTooSmallAndMissing(): void
    {
        $path = $this->tempDirectory() . '/s.zip';
        file_put_contents($path, 'PK');
        try {
            ZipReader::open($path);
            self::fail('Too small.');
        } catch (ZipException $e) {
            self::assertStringContainsString('too small', $e->getMessage());
        }
        $this->expectException(ZipException::class);
        $this->expectExceptionMessage('Cannot open');

        ZipReader::open($path . '.missing');
    }

    public function testCutOffArchives(): void
    {
        $bytes = RawZip::build([['name' => 'a.txt', 'data' => str_repeat('hello ', 1000)], ['name' => 'b.txt', 'data' => 'world']]);

        foreach ([10, 22, 60, 200] as $cut) {
            $broken = substr($bytes, 0, strlen($bytes) - $cut);
            try {
                $reader = $this->open($broken);
                self::assertFalse($reader->verify()->ok(), "cut $cut bytes");
            } catch (ZipException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testDamagedCentralDirectory(): void
    {
        $bytes = RawZip::build([['name' => 'a.txt', 'data' => 'hello'], ['name' => 'b.txt', 'data' => 'world']]);
        $directoryAt = (int) strpos($bytes, pack('V', 0x02014b50));
        $broken = substr_replace($bytes, 'XXXX', $directoryAt + 46 + 5 + 4 + 0, 4);   // the signature of the second record
        $broken = substr_replace($broken, 'XXXX', (int) strpos($broken, pack('V', 0x02014b50), $directoryAt + 4), 4);

        $this->expectException(ZipException::class);
        $this->expectExceptionMessage('damaged');

        iterator_to_array($this->open($broken)->entries());
    }

    public function testAnnouncedEntryCountThatCannotFit(): void
    {
        $this->expectException(ZipException::class);
        $this->expectExceptionMessage('too small for 50000 entries');

        $this->open(RawZip::build([['name' => 'a.txt', 'data' => 'x']], ['entries' => 50000]));
    }

    public function testMultiDiskArchivesAreRefused(): void
    {
        $this->expectException(ZipException::class);
        $this->expectExceptionMessage('multi-disk');

        $this->open(RawZip::build([['name' => 'a.txt', 'data' => 'x']], ['disk' => 1]));
    }

    public function testWrongCrcIsFound(): void
    {
        $problems = $this->problems([['name' => 'a.txt', 'data' => 'hello', 'crc' => 12345]]);

        self::assertCount(1, $problems);
        self::assertStringContainsString('CRC32 does not match', $problems[0]);
    }

    public function testFlippedDataByteIsFound(): void
    {
        $bytes = RawZip::build([['name' => 'a.bin', 'data' => str_repeat('abcdefgh', 100), 'method' => 0]]);
        $at = (int) strpos($bytes, 'abcdefgh') + 100;
        $bytes[$at] = $bytes[$at] === 'x' ? 'y' : 'x';

        $problems = $this->open($bytes)->verify()->problems;

        self::assertCount(1, $problems);
        self::assertStringContainsString('CRC32', $problems[0]);
    }

    public function testDamagedDeflateStream(): void
    {
        $problems = $this->problems([['name' => 'a.txt', 'data' => 'hello hello hello', 'raw' => "\xFF\xFF\xFF\xFF\xFF\xFF", 'size' => 17]]);

        self::assertCount(1, $problems);
        self::assertMatchesRegularExpression('/damaged|too early/', $problems[0]);
    }

    public function testDataThatExpandsBeyondItsDeclaredSizeIsStopped(): void
    {
        // 20 MB of zeros deflate to about 20 KB; the directory claims 100 bytes.
        $bomb = gzdeflate(str_repeat("\0", 20_000_000), 9);
        $reader = $this->open(RawZip::build([['name' => 'bomb', 'raw' => $bomb, 'data' => str_repeat("\0", 100), 'size' => 100, 'crc' => 0]]));
        $delivered = 0;

        try {
            $reader->extract(iterator_to_array($reader->entries(), false)[0], static function (string $chunk) use (&$delivered): void {
                $delivered += strlen($chunk);
            });
            self::fail('The bomb must be stopped.');
        } catch (ZipException $e) {
            self::assertStringContainsString('expands beyond its declared size', $e->getMessage());
        }
        self::assertLessThan(20_000_000, $delivered, 'Not everything was inflated.');
    }

    public function testAbsurdSizeClaimsAreRefusedBeforeReading(): void
    {
        $problems = $this->problems([['name' => 'a.txt', 'data' => 'hello', 'size' => 5_000_000_000]]);

        self::assertCount(1, $problems);
        self::assertStringContainsString('more than deflate can do', $problems[0]);

        $problems = $this->problems([['name' => 'b.txt', 'data' => 'hello', 'compressed' => 900_000_000]]);
        self::assertStringContainsString('outside the file', $problems[0]);
    }

    public function testStoredSizesMustAgree(): void
    {
        $problems = $this->problems([['name' => 'a.txt', 'data' => 'hello', 'method' => 0, 'size' => 99]]);

        self::assertStringContainsString('stored sizes differ', $problems[0]);
    }

    public function testEncryptedAndUnsupportedEntries(): void
    {
        $problems = $this->problems([
            ['name' => 'secret.txt', 'data' => 'x', 'flags' => 1],
            ['name' => 'bz.txt', 'data' => 'x', 'method' => 12, 'raw' => 'xx'],
        ]);

        self::assertCount(2, $problems);
        self::assertStringContainsString('encrypted', $problems[0]);
        self::assertStringContainsString('compression method 12', $problems[1]);
    }

    public function testLocalHeaderMustMatchTheDirectory(): void
    {
        $problems = $this->problems([['name' => 'a.txt', 'data' => 'hello', 'localName' => 'b.txt']]);

        self::assertStringContainsString('names another file', $problems[0]);

        $bytes = RawZip::build([['name' => 'a.txt', 'data' => 'hello']]);
        $bytes[0] = 'X';
        self::assertStringContainsString('local header is missing or damaged', $this->open($bytes)->verify()->problems[0]);
    }

    public function testAnEntryPointingIntoAnotherEntryIsReported(): void
    {
        // b.txt claims to start where a.txt starts; the local header there names a.txt.
        $bytes = RawZip::build([['name' => 'a.txt', 'data' => str_repeat('hello ', 500), 'method' => 0], ['name' => 'b.txt', 'data' => 'second', 'method' => 0, 'offset' => 0]]);

        $problems = $this->open($bytes)->verify()->problems;

        self::assertCount(1, $problems);
        self::assertStringContainsString('names another file', $problems[0]);
    }

    public function testZip64FieldsInTheDirectoryAreUnderstood(): void
    {
        $bytes = RawZip::build([['name' => 'a.txt', 'data' => 'hello', 'method' => 0, 'compressed' => 0xFFFFFFFF, 'size' => 0xFFFFFFFF, 'centralExtra' => RawZip::zip64Extra(5, 5)]]);

        $reader = $this->open($bytes);

        self::assertTrue($reader->verify()->ok(), implode("\n", $reader->verify()->problems));
        self::assertSame('hello', $reader->contents($reader->find('a.txt') ?? throw new \LogicException()));
    }

    public function testMissingOrCutZip64FieldsAreRefused(): void
    {
        foreach ([
            ['size' => 0xFFFFFFFF],
            ['size' => 0xFFFFFFFF, 'centralExtra' => pack('vv', 1, 4) . 'abcd'],
            ['offset' => 0xFFFFFFFF, 'centralExtra' => RawZip::zip64Extra(-1)],
        ] as $override) {
            try {
                $reader = $this->open(RawZip::build([['name' => 'a.txt', 'data' => 'hello', 'method' => 0] + $override]));
                iterator_to_array($reader->entries());
                self::fail('A missing ZIP64 field must be refused.');
            } catch (ZipException $e) {
                self::assertMatchesRegularExpression('/ZIP64/', $e->getMessage());
            }
        }
    }

    public function testContentsRefusesBigEntries(): void
    {
        $reader = $this->open(RawZip::build([['name' => 'a.txt', 'data' => str_repeat('x', 1000)]]));

        $this->expectException(ZipException::class);
        $this->expectExceptionMessage('more than the allowed 100');

        $reader->contents(iterator_to_array($reader->entries(), false)[0], 100);
    }

    public function testCommentAtTheEndIsFound(): void
    {
        $reader = $this->open(RawZip::build([['name' => 'a.txt', 'data' => 'hello']], ['comment' => str_repeat('PK comment ', 100)]));

        self::assertSame(1, $reader->count());
        self::assertTrue($reader->verify()->ok());
    }

    public function testAWalkThroughTheDirectoryContinuesWhereItStopped(): void
    {
        $reader = $this->open(RawZip::build([
            ['name' => 'one.txt', 'data' => '1'],
            ['name' => 'two/two.txt', 'data' => '22'],
            ['name' => 'three.txt', 'data' => '333'],
            ['name' => 'four.txt', 'data' => '4444'],
        ]));
        $all = iterator_to_array($reader->entries());

        foreach ($all as $index => $entry) {
            $rest = iterator_to_array($reader->entries($index + 1, $entry->nextRecord));
            self::assertSame(array_slice(array_map(static fn ($e): string => $e->name, $all), $index + 1), array_values(array_map(static fn ($e): string => $e->name, $rest)));
            self::assertSame(array_slice(array_keys($all), $index + 1), array_keys($rest), 'the keys are the indexes of the records');
        }
        self::assertSame([], iterator_to_array($reader->entries(4, $all[3]->nextRecord)));
    }

    public function testAWalkCannotStartInTheMiddleOfNowhere(): void
    {
        $reader = $this->open(RawZip::build([['name' => 'a.txt', 'data' => 'x']]));

        $this->expectException(ZipException::class);
        iterator_to_array($reader->entries(0, 5));
    }

    public function testAWalkCannotStartBeyondTheDirectory(): void
    {
        $reader = $this->open(RawZip::build([['name' => 'a.txt', 'data' => 'x']]));

        $this->expectException(ZipException::class);
        iterator_to_array($reader->entries(1, 100000));
    }

    public function testASinkCanStopAStoredEntryAndItContinuesLater(): void
    {
        $content = random_bytes(100000);
        $reader = $this->open(RawZip::build([['name' => 'big.bin', 'data' => $content, 'method' => 0]]));
        $entry = $reader->find('big.bin');
        self::assertNotNull($entry);

        $got = '';
        $complete = $reader->extract($entry, static function (string $chunk) use (&$got): bool {
            $got .= $chunk;

            return strlen($got) < 40000;
        });
        self::assertFalse($complete);
        self::assertGreaterThanOrEqual(40000, strlen($got));
        self::assertLessThan(100000, strlen($got));

        $from = strlen($got);
        self::assertTrue($reader->extract($entry, static function (string $chunk) use (&$got): void {
            $got .= $chunk;
        }, $from));
        self::assertSame($content, $got);
    }

    public function testADeflatedEntryCannotBeContinued(): void
    {
        $reader = $this->open(RawZip::build([['name' => 'a.txt', 'data' => str_repeat('abc', 1000)]]));
        $entry = $reader->find('a.txt');
        self::assertNotNull($entry);

        $this->expectException(ZipException::class);
        $reader->extract($entry, static function (string $chunk): void {
        }, 10);
    }

    public function testASinkThatReturnsNothingReadsToTheEnd(): void
    {
        $reader = $this->open(RawZip::build([['name' => 'a.txt', 'data' => str_repeat('abc', 10000)]]));
        $entry = $reader->find('a.txt');
        self::assertNotNull($entry);

        $length = 0;
        self::assertTrue($reader->extract($entry, static function (string $chunk) use (&$length): void {
            $length += strlen($chunk);
        }));
        self::assertSame(30000, $length);
    }
}
