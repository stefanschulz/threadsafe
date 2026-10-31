<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Auth;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ThreadSafe\Auth\QrCode;
use ThreadSafe\Auth\Totp;

/**
 * The QR encoder (docs/PHASE11.md 3.6). Three kinds of proof: the Reed-Solomon codewords of two examples that are printed in tutorials of the
 * standard, the structure of the picture (finder, timing, the format and version information with their own checksums), and a decoder written
 * for this test that reads the picture back the way a scanner does (mask, zigzag, blocks, correction codewords) for every version. The real
 * reader, `zbarimg`, is in tests/Integration/QrCodeZbarTest.php.
 */
final class QrCodeTest extends TestCase
{
    /** @return iterable<string, array{list<int>, list<int>}> data codewords, correction codewords of level M, version 1 */
    public static function reedSolomon(): iterable
    {
        yield '"01234567" (the example of ISO/IEC 18004)' => [
            [0x10, 0x20, 0x0C, 0x56, 0x61, 0x80, 0xEC, 0x11, 0xEC, 0x11, 0xEC, 0x11, 0xEC, 0x11, 0xEC, 0x11],
            [0xA5, 0x24, 0xD4, 0xC1, 0xED, 0x36, 0xC7, 0x87, 0x2C, 0x55],
        ];
        yield '"HELLO WORLD"' => [
            [0x20, 0x5B, 0x0B, 0x78, 0xD1, 0x72, 0xDC, 0x4D, 0x43, 0x40, 0xEC, 0x11, 0xEC, 0x11, 0xEC, 0x11],
            [0xC4, 0x23, 0x27, 0x77, 0xEB, 0xD7, 0xE7, 0xE2, 0x5D, 0x17],
        ];
    }

    /**
     * @param list<int> $data
     * @param list<int> $expected
     */
    #[DataProvider('reedSolomon')]
    public function testTheCorrectionCodewordsOfTheExamples(array $data, array $expected): void
    {
        self::assertSame($expected, QrCode::correctionCodewords($data, 10));
    }

    /** @return iterable<string, array{int, int}> bytes, size of the picture */
    public static function sizes(): iterable
    {
        yield '1 byte' => [1, 21];
        yield '14 bytes (the last of version 1)' => [14, 21];
        yield '15 bytes' => [15, 25];
        yield '26' => [26, 25];
        yield '27' => [27, 29];
        yield '42' => [42, 29];
        yield '43' => [43, 33];
        yield '62' => [62, 33];
        yield '63' => [63, 37];
        yield '84' => [84, 37];
        yield '85' => [85, 41];
        yield '106' => [106, 41];
        yield '107' => [107, 45];
        yield '122 (the last of version 7)' => [122, 45];
        yield '123' => [123, 49];
        yield '152' => [152, 49];
        yield '153' => [153, 53];
        yield '180' => [180, 53];
        yield '181' => [181, 57];
        yield '213 (the last of version 10)' => [213, 57];
    }

    #[DataProvider('sizes')]
    public function testTheVersionFollowsTheLengthOfTheText(int $bytes, int $size): void
    {
        self::assertCount($size, QrCode::modules(str_repeat('a', $bytes)));
        self::assertCount($size, QrCode::modules(str_repeat('a', $bytes))[0]);
    }

    public function testATextThatIsTooLongIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QrCode::modules(str_repeat('a', 214));
    }

    /** @return iterable<string, array{string}> */
    public static function texts(): iterable
    {
        yield 'one letter' => ['a'];
        yield 'a short word' => ['ThreadSafe'];
        yield 'the end of version 1' => [str_repeat('x', 14)];
        yield 'an address of an app' => [Totp::uri('JBSWY3DPEHPK3PXP', 'admin', 'shop.example.com')];
        yield 'all byte values of a text' => [implode('', array_map('chr', range(32, 126)))];
        yield 'umlauts as UTF-8' => ['Größe: äöüß ' . str_repeat('é', 20)];
        yield 'version 7 (with version information)' => [str_repeat('7', 110)];
        yield 'version 8 (blocks of two sizes)' => [str_repeat('8', 140)];
        yield 'version 9' => [str_repeat('Nine', 44)];
        yield 'version 10 (16 bits of length)' => [str_repeat('ten', 70)];
        yield 'the longest' => [str_repeat('z', 213)];
        yield 'long address' => [Totp::uri(Totp::newSecret(), 'admin', 'a-long-host-name.example.com:8443')];
    }

    #[DataProvider('texts')]
    public function testTheDecoderOfTheTestReadsTheTextBack(string $text): void
    {
        self::assertSame($text, $this->decode(QrCode::modules($text)));
    }

    public function testTheFinderPatternsTheTimingAndTheDarkModule(): void
    {
        foreach ([1, 14, 15, 213] as $length) {
            $modules = QrCode::modules(str_repeat('q', $length));
            $size = count($modules);
            foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$left, $top]) {
                for ($dy = 0; $dy < 7; ++$dy) {
                    for ($dx = 0; $dx < 7; ++$dx) {
                        $ring = max(abs($dx - 3), abs($dy - 3));
                        self::assertSame($ring !== 2, $modules[$top + $dy][$left + $dx], "finder at $left,$top, $dx,$dy of $length bytes");
                    }
                }
            }
            // the light separators: the row and the column next to the inner side of each finder
            for ($i = 0; $i < 8; ++$i) {
                self::assertFalse($modules[7][$i] || $modules[$i][7], 'top left');
                self::assertFalse($modules[7][$size - 1 - $i] || $modules[$i][$size - 8], 'top right');
                self::assertFalse($modules[$size - 8][$i] || $modules[$size - 1 - $i][7], 'bottom left');
            }
            for ($i = 8; $i < $size - 8; ++$i) {
                self::assertSame($i % 2 === 0, $modules[6][$i], "timing row, $i");
                self::assertSame($i % 2 === 0, $modules[$i][6], "timing column, $i");
            }
            self::assertTrue($modules[$size - 8][8], 'the dark module');
        }
    }

    public function testTheFormatInformationHasItsOwnChecksumAndStandsTwice(): void
    {
        foreach ([1, 20, 60, 130, 213] as $length) {
            $modules = QrCode::modules(str_repeat('f', $length));
            $size = count($modules);

            $first = $this->readBits($modules, [[8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8], [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8]]);
            $second = [];
            for ($i = 0; $i < 8; ++$i) {
                $second[] = [$size - 1 - $i, 8];
            }
            for ($i = 8; $i < 15; ++$i) {
                $second[] = [8, $size - 15 + $i];
            }
            self::assertSame($first, $this->readBits($modules, $second), "both copies agree ($length bytes)");

            $data = ($first ^ 0x5412) >> 10;
            self::assertSame(0, $data >> 3, 'the level is M');
            $remainder = $data;
            for ($i = 0; $i < 10; ++$i) {
                $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
            }
            self::assertSame(($first ^ 0x5412) & 0x3FF, $remainder, 'the checksum of the 15 bits');
        }
    }

    public function testTheVersionInformationFromVersionSevenOn(): void
    {
        foreach ([7 => 110, 8 => 140, 9 => 170, 10 => 200] as $version => $length) {
            $modules = QrCode::modules(str_repeat('v', $length));
            $size = count($modules);
            self::assertSame(17 + 4 * $version, $size);

            $bits = 0;
            for ($i = 0; $i < 18; ++$i) {
                $a = $size - 11 + $i % 3;
                $b = intdiv($i, 3);
                self::assertSame($modules[$b][$a], $modules[$a][$b], 'both places');
                $bits |= ($modules[$b][$a] ? 1 : 0) << $i;
            }
            self::assertSame($version, $bits >> 12);
            $remainder = $version;
            for ($i = 0; $i < 12; ++$i) {
                $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
            }
            self::assertSame($bits & 0xFFF, $remainder);
        }
    }

    public function testThePictureIsTheSameEveryTime(): void
    {
        self::assertSame(QrCode::modules('same'), QrCode::modules('same'));
        self::assertNotSame(QrCode::modules('same'), QrCode::modules('other'));
    }

    public function testTheSvgIsSelfContainedAndHasTheQuietZone(): void
    {
        $svg = QrCode::svg('ThreadSafe', 'The key as a QR code "<test>"');
        $size = count(QrCode::modules('ThreadSafe'));

        self::assertStringStartsWith('<svg class="qr" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . ($size + 8) . ' ' . ($size + 8) . '"', $svg);
        self::assertStringContainsString('role="img"', $svg);
        self::assertStringContainsString('aria-label="The key as a QR code &quot;&lt;test&gt;&quot;"', $svg);
        self::assertStringContainsString('<rect width="' . ($size + 8) . '" height="' . ($size + 8) . '" fill="#fff"/>', $svg, 'white, whatever the scheme of the page');
        self::assertStringContainsString('fill="#000"', $svg);
        self::assertStringNotContainsString('http://', str_replace('xmlns="http://www.w3.org/2000/svg"', '', $svg), 'no other address');
        self::assertStringNotContainsString('<script', $svg);
        self::assertStringNotContainsString('style=', $svg, 'the content security policy has no inline style');
        self::assertSame(1, preg_match('/d="M4 4h7v1h-7z/', $svg), 'the first run is the top edge of the first finder, four modules in');
    }

    public function testThePathOfTheSvgDrawsExactlyTheModules(): void
    {
        foreach (['ThreadSafe', Totp::uri('JBSWY3DPEHPK3PXP', 'admin', 'shop.example.com'), str_repeat('z', 213)] as $text) {
            $modules = QrCode::modules($text);
            $size = count($modules);
            self::assertSame(1, preg_match('/ d="([^"]*)"/', QrCode::svg($text, 'x'), $m));

            $drawn = array_fill(0, $size, array_fill(0, $size, false));
            self::assertGreaterThan(0, preg_match_all('/M(\d+) (\d+)h(\d+)v1h-(\d+)z/', $m[1] ?? '', $runs, PREG_SET_ORDER));
            foreach ($runs as [, $x, $y, $length, $back]) {
                self::assertSame($length, $back);
                for ($i = 0; $i < (int) $length; ++$i) {
                    self::assertFalse($drawn[(int) $y - 4][(int) $x - 4 + $i], 'no module twice');
                    $drawn[(int) $y - 4][(int) $x - 4 + $i] = true;
                }
            }
            self::assertSame($modules, $drawn, 'the path is the picture, shifted by the quiet zone');
        }
    }

    // ------------------------------------------------------------------------------------------------- a reader

    /**
     * @param array<int, array<int, bool>> $modules
     * @param list<array{int, int}> $places x and y of the bits, bit 0 first
     */
    private function readBits(array $modules, array $places): int
    {
        $value = 0;
        foreach ($places as $i => [$x, $y]) {
            $value |= ($modules[$y][$x] ? 1 : 0) << $i;
        }

        return $value;
    }

    /**
     * Reads a picture back as a scanner does: the mask from the format information, the codewords in the zigzag, the blocks, a check of every
     * correction codeword, then the header of byte mode and the bytes.
     *
     * @param array<int, array<int, bool>> $modules
     */
    private function decode(array $modules): string
    {
        $size = count($modules);
        $version = ($size - 17) / 4;
        $first = $this->readBits($modules, [[8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8], [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8]]);
        $mask = (($first ^ 0x5412) >> 10) & 7;

        $function = (new ReflectionMethod(QrCode::class, 'functionPatterns'))->invoke(null, $version)[0];
        $constant = (new ReflectionClass(QrCode::class))->getReflectionConstant('BLOCKS');
        self::assertNotFalse($constant);
        $blocks = $constant->getValue()[$version];
        [$short, $shortData, $long, $correction] = $blocks;

        $bits = [];
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vertical = 0; $vertical < $size; ++$vertical) {
                for ($j = 0; $j < 2; ++$j) {
                    $x = $right - $j;
                    $y = ((($right + 1) & 2) === 0) ? $size - 1 - $vertical : $vertical;
                    if ($function[$y][$x]) {
                        continue;
                    }
                    $dark = $modules[$y][$x];
                    $invert = match ($mask) {
                        0 => ($x + $y) % 2 === 0,
                        1 => $y % 2 === 0,
                        2 => $x % 3 === 0,
                        3 => ($x + $y) % 3 === 0,
                        4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                        5 => $x * $y % 2 + $x * $y % 3 === 0,
                        6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                        default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                    };
                    $bits[] = $dark !== $invert ? 1 : 0;
                }
            }
        }
        $codewords = [];
        foreach (array_chunk($bits, 8) as $chunk) {
            if (count($chunk) === 8) {
                $codewords[] = (int) bindec(implode('', $chunk));
            }
        }

        // undo the interleaving
        $count = $short + $long;
        $data = array_fill(0, $count, []);
        $check = array_fill(0, $count, []);
        $position = 0;
        for ($i = 0; $i <= $shortData; ++$i) {
            for ($b = 0; $b < $count; ++$b) {
                if ($i < $shortData + ($b >= $short ? 1 : 0)) {
                    $data[$b][] = $codewords[$position++];
                }
            }
        }
        for ($i = 0; $i < $correction; ++$i) {
            for ($b = 0; $b < $count; ++$b) {
                $check[$b][] = $codewords[$position++];
            }
        }
        foreach ($data as $b => $block) {
            self::assertSame($check[$b], QrCode::correctionCodewords($block, $correction), "the correction codewords of block $b");
        }

        $stream = '';
        foreach ($data as $block) {
            foreach ($block as $codeword) {
                $stream .= str_pad(decbin($codeword), 8, '0', STR_PAD_LEFT);
            }
        }
        self::assertSame('0100', substr($stream, 0, 4), 'byte mode');
        $lengthBits = $version >= 10 ? 16 : 8;
        $length = (int) bindec(substr($stream, 4, $lengthBits));
        $text = '';
        for ($i = 0; $i < $length; ++$i) {
            $text .= chr((int) bindec(substr($stream, 4 + $lengthBits + 8 * $i, 8)));
        }

        return $text;
    }
}
