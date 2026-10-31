<?php

declare(strict_types=1);

namespace ThreadSafe\Auth;

use InvalidArgumentException;

/**
 * A QR code in byte mode with error correction level M, versions 1 to 10 (up to 213 bytes: the `otpauth://` address of an authenticator app is 130
 * to 200), drawn as an inline SVG (docs/PHASE11.md 3.6). Plain PHP, no dependency. The steps are those of ISO/IEC 18004: the data codewords, Reed-Solomon
 * codewords per block and their interleaving, the function patterns, the zigzag placement, the mask with the lowest penalty, the format and version
 * information.
 *
 * The picture is black on white with a quiet zone of four modules in every colour scheme of the page: a scanner needs the contrast, not the theme.
 */
final class QrCode
{
    /** The highest version this class makes. */
    public const int MAX_VERSION = 10;

    /**
     * Level M by version (index = version): the codewords of data per block and the number of blocks with one more, the codewords of
     * correction per block, and the blocks. [short blocks, data codewords of a short block, long blocks (one codeword more), correction per block]
     *
     * @var array<int, array{int, int, int, int}>
     */
    private const array BLOCKS = [
        1 => [1, 16, 0, 10],
        2 => [1, 28, 0, 16],
        3 => [1, 44, 0, 26],
        4 => [2, 32, 0, 18],
        5 => [2, 43, 0, 24],
        6 => [4, 27, 0, 16],
        7 => [4, 31, 0, 18],
        8 => [2, 38, 2, 22],
        9 => [3, 36, 2, 22],
        10 => [4, 43, 1, 26],
    ];

    /** The centres of the alignment patterns per version (rows and columns alike). */
    private const array ALIGNMENT = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    private function __construct()
    {
    }

    /**
     * The modules of the code, true = dark; row by row, without the quiet zone.
     *
     * @return array<int, array<int, bool>>
     *
     * @throws InvalidArgumentException the text does not fit into version 10
     */
    public static function modules(string $text): array
    {
        $version = self::versionFor(strlen($text));
        $codewords = self::interleave(self::dataCodewords($text, $version), $version);
        $size = 17 + 4 * $version;

        [$function, $base] = self::functionPatterns($version);
        $placed = self::place($base, $function, $codewords, $size);

        $best = null;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; ++$mask) {
            $candidate = self::applyMask($placed, $function, $mask, $size);
            foreach (self::formatCells($mask, $size) as [$x, $y, $dark]) {
                $candidate[$y][$x] = $dark;
            }
            $penalty = self::penalty($candidate, $size);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $candidate;
            }
        }

        return $best ?? [];
    }

    /**
     * The code as an inline SVG element, 4 modules of quiet zone around it, black on white.
     *
     * @param string $label the text for a screen reader (what the picture is)
     *
     * @throws InvalidArgumentException the text does not fit into version 10
     */
    public static function svg(string $text, string $label): string
    {
        $modules = self::modules($text);
        $size = count($modules);
        $total = $size + 8;
        $path = '';
        foreach ($modules as $y => $row) {
            $x = 0;
            while ($x < $size) {
                if (!$row[$x]) {
                    ++$x;
                    continue;
                }
                $start = $x;
                while ($x < $size && $row[$x]) {
                    ++$x;
                }
                $path .= 'M' . ($start + 4) . ' ' . ($y + 4) . 'h' . ($x - $start) . 'v1h-' . ($x - $start) . 'z';
            }
        }

        return '<svg class="qr" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $total . '" shape-rendering="crispEdges" role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
            . '<rect width="' . $total . '" height="' . $total . '" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
    }

    // ------------------------------------------------------------------------------------------------- the data

    private static function versionFor(int $bytes): int
    {
        for ($version = 1; $version <= self::MAX_VERSION; ++$version) {
            // 4 bits of mode, 8 bits of length (16 from version 10), and the bytes
            $capacity = self::dataCodewordCount($version) * 8 - 4 - ($version >= 10 ? 16 : 8);
            if ($bytes * 8 <= $capacity) {
                return $version;
            }
        }

        throw new InvalidArgumentException('The text is too long for a QR code of version ' . self::MAX_VERSION . ' (213 bytes).');
    }

    private static function dataCodewordCount(int $version): int
    {
        [$short, $shortData, $long] = self::BLOCKS[$version];

        return $short * $shortData + $long * ($shortData + 1);
    }

    /**
     * Mode, length, the bytes, the terminator and the padding, as codewords.
     *
     * @return array<int, int>
     */
    private static function dataCodewords(string $text, int $version): array
    {
        $bits = [];
        $append = static function (int $value, int $length) use (&$bits): void {
            for ($i = $length - 1; $i >= 0; --$i) {
                $bits[] = ($value >> $i) & 1;
            }
        };
        $append(0b0100, 4);
        $append(strlen($text), $version >= 10 ? 16 : 8);
        foreach (str_split($text) as $character) {
            $append(ord($character), 8);
        }
        $capacity = self::dataCodewordCount($version) * 8;
        $append(0, min(4, $capacity - count($bits)));
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        $codewords = [];
        foreach (array_chunk($bits, 8) as $byte) {
            $codewords[] = (int) bindec(implode('', $byte));
        }
        for ($pad = 0xEC; count($codewords) < self::dataCodewordCount($version); $pad ^= 0xEC ^ 0x11) {
            $codewords[] = $pad;
        }

        return $codewords;
    }

    /**
     * The correction codewords of every block, and data and correction interleaved as the standard says.
     *
     * @param array<int, int> $data
     *
     * @return array<int, int>
     */
    private static function interleave(array $data, int $version): array
    {
        [$short, $shortData, $long, $correction] = self::BLOCKS[$version];
        $blocks = [];
        $offset = 0;
        for ($i = 0; $i < $short + $long; ++$i) {
            $length = $shortData + ($i >= $short ? 1 : 0);
            $blocks[] = array_slice($data, $offset, $length);
            $offset += $length;
        }
        $divisor = self::divisor($correction);
        $checks = array_map(static fn (array $block): array => self::remainder($block, $divisor), $blocks);

        $out = [];
        for ($i = 0; $i <= $shortData; ++$i) {
            foreach ($blocks as $block) {
                if ($i < count($block)) {
                    $out[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $correction; ++$i) {
            foreach ($checks as $check) {
                $out[] = $check[$i];
            }
        }

        return $out;
    }

    /**
     * The Reed-Solomon codewords of one block (the remainder of the block times x^degree divided by the generator polynomial).
     *
     * @param array<int, int> $data
     *
     * @return array<int, int>
     */
    public static function correctionCodewords(array $data, int $degree): array
    {
        return self::remainder($data, self::divisor($degree));
    }

    /** @return array<int, int> the generator polynomial of the degree, without its leading 1 */
    private static function divisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; ++$i) {
            for ($j = 0; $j < $degree; ++$j) {
                $result[$j] = self::multiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::multiply($root, 0x02);
        }

        return $result;
    }

    /**
     * @param array<int, int> $data
     * @param array<int, int> $divisor
     *
     * @return array<int, int>
     */
    private static function remainder(array $data, array $divisor): array
    {
        $degree = count($divisor);
        $result = array_fill(0, $degree, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coefficient) {
                $result[$i] ^= self::multiply($coefficient, $factor);
            }
        }

        return $result;
    }

    /** Multiplication in GF(2^8) with the polynomial x^8 + x^4 + x^3 + x^2 + 1. */
    private static function multiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; --$i) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z;
    }

    // ------------------------------------------------------------------------------------------------- the picture

    /**
     * Which modules belong to a pattern (so that the data and the mask leave them alone), and the picture with the patterns drawn.
     *
     * @return array{array<int, array<int, bool>>, array<int, array<int, bool>>}
     */
    private static function functionPatterns(int $version): array
    {
        $size = 17 + 4 * $version;
        $function = array_fill(0, $size, array_fill(0, $size, false));
        $modules = array_fill(0, $size, array_fill(0, $size, false));
        $set = static function (int $x, int $y, bool $dark) use (&$function, &$modules, $size): void {
            if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) {
                $modules[$y][$x] = $dark;
                $function[$y][$x] = true;
            }
        };

        for ($i = 0; $i < $size; ++$i) {
            $set(6, $i, $i % 2 === 0);
            $set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; ++$dy) {
                for ($dx = -4; $dx <= 4; ++$dx) {
                    $distance = max(abs($dx), abs($dy));
                    $set($cx + $dx, $cy + $dy, $distance !== 2 && $distance !== 4);
                }
            }
        }
        $centres = self::ALIGNMENT[$version];
        $count = count($centres);
        foreach ($centres as $i => $cx) {
            foreach ($centres as $j => $cy) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $count - 1) || ($i === $count - 1 && $j === 0)) {
                    continue; // the corners with a finder pattern
                }
                for ($dy = -2; $dy <= 2; ++$dy) {
                    for ($dx = -2; $dx <= 2; ++$dx) {
                        $set($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        // the places of the format information are function modules too (they get their bits once the mask is chosen)
        foreach (self::formatCells(0, $size) as [$x, $y, $dark]) {
            $set($x, $y, $dark);
        }
        if ($version >= 7) {
            $remainder = $version;
            for ($i = 0; $i < 12; ++$i) {
                $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
            }
            $bits = ($version << 12) | $remainder;
            for ($i = 0; $i < 18; ++$i) {
                $dark = (($bits >> $i) & 1) === 1;
                $a = $size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $set($a, $b, $dark);
                $set($b, $a, $dark);
            }
        }

        return [$function, $modules];
    }

    /**
     * The modules that carry the 15 bits of format information (level M and the mask), in both places, and the dark module.
     *
     * @return array<int, array{int, int, bool}> column, row, dark
     */
    private static function formatCells(int $mask, int $size): array
    {
        $data = (0b00 << 3) | $mask; // level M is 00
        $remainder = $data;
        for ($i = 0; $i < 10; ++$i) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }
        $bits = (($data << 10) | $remainder) ^ 0x5412;
        $bit = static fn (int $i): bool => (($bits >> $i) & 1) === 1;

        $cells = [];
        for ($i = 0; $i <= 5; ++$i) {
            $cells[] = [8, $i, $bit($i)];
        }
        $cells[] = [8, 7, $bit(6)];
        $cells[] = [8, 8, $bit(7)];
        $cells[] = [7, 8, $bit(8)];
        for ($i = 9; $i < 15; ++$i) {
            $cells[] = [14 - $i, 8, $bit($i)];
        }
        for ($i = 0; $i < 8; ++$i) {
            $cells[] = [$size - 1 - $i, 8, $bit($i)];
        }
        for ($i = 8; $i < 15; ++$i) {
            $cells[] = [8, $size - 15 + $i, $bit($i)];
        }
        $cells[] = [8, $size - 8, true]; // the dark module

        return $cells;
    }

    /**
     * The codewords in the zigzag of the standard, into the modules that no pattern holds.
     *
     * @param array<int, array<int, bool>> $modules
     * @param array<int, array<int, bool>> $function
     * @param array<int, int>        $codewords
     *
     * @return array<int, array<int, bool>>
     */
    private static function place(array $modules, array $function, array $codewords, int $size): array
    {
        $index = 0;
        $total = count($codewords) * 8;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vertical = 0; $vertical < $size; ++$vertical) {
                for ($j = 0; $j < 2; ++$j) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $size - 1 - $vertical : $vertical;
                    if (!$function[$y][$x] && $index < $total) {
                        $modules[$y][$x] = ((($codewords[$index >> 3] >> (7 - ($index & 7))) & 1) === 1);
                        ++$index;
                    }
                }
            }
        }

        return $modules;
    }

    /**
     * @param array<int, array<int, bool>> $modules
     * @param array<int, array<int, bool>> $function
     *
     * @return array<int, array<int, bool>>
     */
    private static function applyMask(array $modules, array $function, int $mask, int $size): array
    {
        for ($y = 0; $y < $size; ++$y) {
            for ($x = 0; $x < $size; ++$x) {
                if ($function[$y][$x]) {
                    continue;
                }
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
                if ($invert) {
                    $modules[$y][$x] = !$modules[$y][$x];
                }
            }
        }

        return $modules;
    }

    /**
     * The penalty of the standard (runs of five or more, 2x2 blocks, patterns like a finder, the share of dark modules): the lower, the
     * easier a scanner finds its way.
     *
     * @param array<int, array<int, bool>> $modules
     */
    private static function penalty(array $modules, int $size): int
    {
        $score = 0;
        $lines = [];
        for ($i = 0; $i < $size; ++$i) {
            $row = $modules[$i];
            $column = array_column($modules, $i);
            $lines[] = $row;
            $lines[] = $column;
        }
        foreach ($lines as $line) {
            $run = 1;
            for ($i = 1; $i < $size; ++$i) {
                if ($line[$i] === $line[$i - 1]) {
                    ++$run;
                    continue;
                }
                $score += $run >= 5 ? 3 + $run - 5 : 0;
                $run = 1;
            }
            $score += $run >= 5 ? 3 + $run - 5 : 0;

            $text = implode('', array_map(static fn (bool $dark): string => $dark ? '1' : '0', $line));
            $score += 40 * (substr_count($text, '10111010000') + substr_count($text, '00001011101'));
        }
        for ($y = 0; $y < $size - 1; ++$y) {
            for ($x = 0; $x < $size - 1; ++$x) {
                if ($modules[$y][$x] === $modules[$y][$x + 1] && $modules[$y][$x] === $modules[$y + 1][$x] && $modules[$y][$x] === $modules[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        $dark = 0;
        foreach ($modules as $row) {
            $dark += count(array_filter($row));
        }
        $score += 10 * (int) (abs($dark * 20 - $size * $size * 10) / ($size * $size));

        return $score;
    }
}
