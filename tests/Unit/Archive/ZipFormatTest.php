<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Archive;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Archive\Compression;
use ThreadSafe\Archive\ZipFormat;

/**
 * The record encoders as pure functions - including values above 4 GiB that no test archive could hold.
 */
final class ZipFormatTest extends TestCase
{
    public function testDosDateTimeRoundTripInUtc(): void
    {
        $unix = (int) gmmktime(13, 45, 58, 10, 20, 2026);

        [$time, $date] = ZipFormat::dosDateTime($unix);

        self::assertSame($unix, ZipFormat::unixFromDos($time, $date));
        self::assertSame((13 << 11) | (45 << 5) | 29, $time);
        self::assertSame(((2026 - 1980) << 9) | (10 << 5) | 20, $date);
    }

    public function testDosDateTimeHasTwoSecondSteps(): void
    {
        $odd = (int) gmmktime(0, 0, 59, 1, 1, 2026);

        self::assertSame($odd - 1, ZipFormat::unixFromDos(...ZipFormat::dosDateTime($odd)));
    }

    public function testDosDateTimeIsClamped(): void
    {
        self::assertSame((int) gmmktime(0, 0, 0, 1, 1, 1980), ZipFormat::unixFromDos(...ZipFormat::dosDateTime(0)));
        self::assertSame((int) gmmktime(23, 59, 58, 12, 31, 2107), ZipFormat::unixFromDos(...ZipFormat::dosDateTime(PHP_INT_MAX)));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function names(): iterable
    {
        yield 'plain' => ['files/a.txt', true];
        yield 'directory' => ['files/dir/', true];
        yield 'dot in name' => ['files/.htaccess', true];
        yield 'dots inside' => ['files/a..b', true];
        yield 'unicode' => ['files/ümläut ✓', true];
        yield 'empty' => ['', false];
        yield 'parent' => ['..', false];
        yield 'parent in the middle' => ['a/../b', false];
        yield 'current directory' => ['a/./b', false];
        yield 'absolute' => ['/a', false];
        yield 'drive' => ['c:file', false];
        yield 'backslash' => ['a\\b', false];
        yield 'NUL' => ["a\0", false];
        yield 'empty segment' => ['a//b', false];
        yield 'too long' => [str_repeat('a', 70000), false];
    }

    #[DataProvider('names')]
    public function testNameSafety(string $name, bool $safe): void
    {
        self::assertSame($safe, ZipFormat::unsafeReason($name) === null, $name);
    }

    public function testCentralRecordWithValuesAboveFourGiB(): void
    {
        $size = 6_000_000_000;
        $compressed = 5_000_000_000;
        $offset = 7_000_000_000;

        $record = ZipFormat::centralRecord('big.bin', ZipFormat::FLAG_DESCRIPTOR, 8, 1_800_000_000, 0100644, false, 0xDEADBEEF, $compressed, $size, $offset, true);

        $fixed = unpack('Vsig/vmadeBy/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vnameLength/vextraLength/vcommentLength/vdisk/vinternal/Vexternal/Voffset', $record);
        self::assertIsArray($fixed);
        self::assertSame(ZipFormat::CENTRAL_HEADER, $fixed['sig']);
        self::assertSame(45, $fixed['needed']);
        self::assertSame(0xFFFFFFFF, $fixed['size']);
        self::assertSame(0xFFFFFFFF, $fixed['compressed']);
        self::assertSame(0xFFFFFFFF, $fixed['offset']);
        self::assertSame(0xDEADBEEF, $fixed['crc']);
        self::assertSame('big.bin', substr($record, 46, $fixed['nameLength']));
        $fields = ZipFormat::parseExtra(substr($record, 46 + $fixed['nameLength']));
        self::assertSame([$size, $compressed, $offset], array_values(unpack('P3', $fields[ZipFormat::EXTRA_ZIP64]) ?: []), 'Sizes and offset in the order of the specification.');
        self::assertArrayHasKey(ZipFormat::EXTRA_TIMESTAMP, $fields);
    }

    public function testCentralRecordUsesZip64OnlyForTheFieldsThatNeedIt(): void
    {
        $record = ZipFormat::centralRecord('a', 0, 8, 0, 0100644, false, 1, 10, 20, 7_000_000_000, false);
        $fixed = unpack('Vsig/vmadeBy/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vnameLength/vextraLength', $record);
        self::assertIsArray($fixed);

        self::assertSame(10, $fixed['compressed']);
        self::assertSame(20, $fixed['size']);
        $fields = ZipFormat::parseExtra(substr($record, 46 + 1));
        self::assertSame([7_000_000_000], array_values(unpack('P1', $fields[ZipFormat::EXTRA_ZIP64]) ?: []));
        self::assertSame(45, $fixed['needed']);
    }

    public function testEndRecordsClassicAndZip64(): void
    {
        self::assertSame(22, strlen(ZipFormat::endRecords(10, 1000, 5000, false)));
        self::assertSame(22 + 56 + 20, strlen(ZipFormat::endRecords(10, 1000, 5000, true)), 'Forced.');
        self::assertSame(22 + 56 + 20, strlen(ZipFormat::endRecords(70000, 1000, 5000, false)), 'More entries than 16 bits count.');
        self::assertSame(22 + 56 + 20, strlen(ZipFormat::endRecords(10, 1000, 5_000_000_000, false)), 'Offset beyond 32 bits.');
        self::assertSame(22 + 56 + 20, strlen(ZipFormat::endRecords(10, 5_000_000_000, 100, false)), 'Directory beyond 32 bits.');

        $records = ZipFormat::endRecords(70000, 1234, 5_000_000_000, false);
        $end64 = unpack('Vsig/Psize/vmadeBy/vneeded/Vdisk/VcdDisk/PentriesDisk/Pentries/PcdSize/PcdOffset', $records);
        $locator = unpack('Vsig/Vdisk/Poffset/Vdisks', $records, 56);
        $end = unpack('Vsig/vdisk/vcdDisk/ventriesDisk/ventries/VcdSize/VcdOffset/vcomment', $records, 76);
        self::assertIsArray($end64);
        self::assertIsArray($locator);
        self::assertIsArray($end);
        self::assertSame([70000, 1234, 5_000_000_000], [$end64['entries'], $end64['cdSize'], $end64['cdOffset']]);
        self::assertSame(5_000_000_000 + 1234, $locator['offset'], 'The locator points at the ZIP64 end record, which follows the directory.');
        self::assertSame([0xFFFF, 0xFFFFFFFF, 0xFFFFFFFF], [$end['entries'], $end['cdSize'], $end['cdOffset']]);
    }

    public function testDataDescriptorWidths(): void
    {
        self::assertSame(16, strlen(ZipFormat::dataDescriptor(1, 2, 3, false)));
        self::assertSame(24, strlen(ZipFormat::dataDescriptor(1, 2, 3, true)));
    }

    public function testParseExtraIgnoresCutOffFields(): void
    {
        $extra = pack('vv', 0x5455, 5) . "\x01abcd" . pack('vv', 0x0001, 16) . 'short';

        $fields = ZipFormat::parseExtra($extra);

        self::assertSame([0x5455], array_keys($fields));
        self::assertSame([], ZipFormat::parseExtra('ab'));
    }

    public function testCompressedFileTypesAreStored(): void
    {
        foreach (['a.jpg', 'dir/b.PNG', 'c.zip', 'd.tar.gz', 'e.woff2', 'f.mp4'] as $name) {
            self::assertTrue(Compression::shouldStore($name), $name);
        }
        foreach (['a.php', 'b.txt', 'c.sql', 'd.js', 'noextension', '.htaccess', 'e.svg'] as $name) {
            self::assertFalse(Compression::shouldStore($name), $name);
        }
    }
}
