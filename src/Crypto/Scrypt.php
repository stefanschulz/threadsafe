<?php

declare(strict_types=1);

namespace ThreadSafe\Crypto;

/**
 * scrypt (RFC 7914) in plain PHP, for the passphrase of the `age` format. PHP has no scrypt with the parameters `age` uses, so this is
 * the algorithm itself: slow (2.7 s and 42 MB for N = 2^15, r = 8, p = 1, docs/PHASE5.md section 8, finding 4), which is why a
 * passphrase made by this program uses N = 2^15 and not the 2^18 of the `age` tool, and why a file with a higher work factor is refused
 * when this host's memory limit does not allow it.
 */
final class Scrypt
{
    /** Bytes of PHP memory per unit of N for r = 8 (measured: 42 MB at 2^15), and a base for the rest of the work. */
    private const int BYTES_PER_N = 1300;

    private const int BASE = 3_000_000;

    /**
     * @return string $length bytes
     */
    public static function derive(string $password, string $salt, int $n, int $r, int $p, int $length): string
    {
        $words = 32 * $r;
        $b = hash_pbkdf2('sha256', $password, $salt, 1, max(0, $p * 128 * $r), true);
        for ($i = 0; $i < $p; ++$i) {
            $block = substr($b, $i * 128 * $r, 128 * $r);
            /** @var list<int> $x */
            $x = array_values(unpack('V' . $words, $block) ?: []);
            $v = [];
            for ($j = 0; $j < $n; ++$j) {
                $v[$j] = pack('V*', ...$x);
                $x = self::blockMix($x, $r);
            }
            for ($j = 0; $j < $n; ++$j) {
                $k = $x[(2 * $r - 1) * 16] & ($n - 1);
                $other = array_values(unpack('V' . $words, $v[$k]) ?: []);
                for ($w = 0; $w < $words; ++$w) {
                    $x[$w] ^= $other[$w];
                }
                $x = self::blockMix($x, $r);
            }
            $b = substr_replace($b, pack('V*', ...$x), $i * 128 * $r, 128 * $r);
        }

        return hash_pbkdf2('sha256', $password, $b, 1, max(0, $length), true);
    }

    /** About how much PHP memory deriving with this work factor (r = 8) needs. */
    public static function memoryFor(int $logN): int
    {
        return self::BASE + self::BYTES_PER_N * (1 << $logN);
    }

    /**
     * @param array<int, int> $b 32 * r words
     *
     * @return list<int>
     */
    private static function blockMix(array $b, int $r): array
    {
        $x = array_slice($b, (2 * $r - 1) * 16, 16);
        $even = [];
        $odd = [];
        for ($i = 0; $i < 2 * $r; ++$i) {
            $block = array_slice($b, $i * 16, 16);
            for ($k = 0; $k < 16; ++$k) {
                $x[$k] ^= $block[$k];
            }
            $x = self::salsa8($x);
            if ($i % 2 === 0) {
                array_push($even, ...$x);
            } else {
                array_push($odd, ...$x);
            }
        }

        return [...$even, ...$odd];
    }

    /**
     * @param array<int, int> $b 16 words
     *
     * @return list<int>
     */
    private static function salsa8(array $b): array
    {
        [$x0, $x1, $x2, $x3, $x4, $x5, $x6, $x7, $x8, $x9, $x10, $x11, $x12, $x13, $x14, $x15] = $b;
        $m = 0xFFFFFFFF;
        for ($i = 0; $i < 4; ++$i) {
            $t = ($x0 + $x12) & $m; $x4 ^= (($t << 7) | ($t >> 25)) & $m;
            $t = ($x4 + $x0) & $m; $x8 ^= (($t << 9) | ($t >> 23)) & $m;
            $t = ($x8 + $x4) & $m; $x12 ^= (($t << 13) | ($t >> 19)) & $m;
            $t = ($x12 + $x8) & $m; $x0 ^= (($t << 18) | ($t >> 14)) & $m;
            $t = ($x5 + $x1) & $m; $x9 ^= (($t << 7) | ($t >> 25)) & $m;
            $t = ($x9 + $x5) & $m; $x13 ^= (($t << 9) | ($t >> 23)) & $m;
            $t = ($x13 + $x9) & $m; $x1 ^= (($t << 13) | ($t >> 19)) & $m;
            $t = ($x1 + $x13) & $m; $x5 ^= (($t << 18) | ($t >> 14)) & $m;
            $t = ($x10 + $x6) & $m; $x14 ^= (($t << 7) | ($t >> 25)) & $m;
            $t = ($x14 + $x10) & $m; $x2 ^= (($t << 9) | ($t >> 23)) & $m;
            $t = ($x2 + $x14) & $m; $x6 ^= (($t << 13) | ($t >> 19)) & $m;
            $t = ($x6 + $x2) & $m; $x10 ^= (($t << 18) | ($t >> 14)) & $m;
            $t = ($x15 + $x11) & $m; $x3 ^= (($t << 7) | ($t >> 25)) & $m;
            $t = ($x3 + $x15) & $m; $x7 ^= (($t << 9) | ($t >> 23)) & $m;
            $t = ($x7 + $x3) & $m; $x11 ^= (($t << 13) | ($t >> 19)) & $m;
            $t = ($x11 + $x7) & $m; $x15 ^= (($t << 18) | ($t >> 14)) & $m;
            $t = ($x0 + $x3) & $m; $x1 ^= (($t << 7) | ($t >> 25)) & $m;
            $t = ($x1 + $x0) & $m; $x2 ^= (($t << 9) | ($t >> 23)) & $m;
            $t = ($x2 + $x1) & $m; $x3 ^= (($t << 13) | ($t >> 19)) & $m;
            $t = ($x3 + $x2) & $m; $x0 ^= (($t << 18) | ($t >> 14)) & $m;
            $t = ($x5 + $x4) & $m; $x6 ^= (($t << 7) | ($t >> 25)) & $m;
            $t = ($x6 + $x5) & $m; $x7 ^= (($t << 9) | ($t >> 23)) & $m;
            $t = ($x7 + $x6) & $m; $x4 ^= (($t << 13) | ($t >> 19)) & $m;
            $t = ($x4 + $x7) & $m; $x5 ^= (($t << 18) | ($t >> 14)) & $m;
            $t = ($x10 + $x9) & $m; $x11 ^= (($t << 7) | ($t >> 25)) & $m;
            $t = ($x11 + $x10) & $m; $x8 ^= (($t << 9) | ($t >> 23)) & $m;
            $t = ($x8 + $x11) & $m; $x9 ^= (($t << 13) | ($t >> 19)) & $m;
            $t = ($x9 + $x8) & $m; $x10 ^= (($t << 18) | ($t >> 14)) & $m;
            $t = ($x15 + $x14) & $m; $x12 ^= (($t << 7) | ($t >> 25)) & $m;
            $t = ($x12 + $x15) & $m; $x13 ^= (($t << 9) | ($t >> 23)) & $m;
            $t = ($x13 + $x12) & $m; $x14 ^= (($t << 13) | ($t >> 19)) & $m;
            $t = ($x14 + $x13) & $m; $x15 ^= (($t << 18) | ($t >> 14)) & $m;
        }

        return [
            ($b[0] + $x0) & $m, ($b[1] + $x1) & $m, ($b[2] + $x2) & $m, ($b[3] + $x3) & $m, ($b[4] + $x4) & $m, ($b[5] + $x5) & $m, ($b[6] + $x6) & $m, ($b[7] + $x7) & $m,
            ($b[8] + $x8) & $m, ($b[9] + $x9) & $m, ($b[10] + $x10) & $m, ($b[11] + $x11) & $m, ($b[12] + $x12) & $m, ($b[13] + $x13) & $m, ($b[14] + $x14) & $m, ($b[15] + $x15) & $m,
        ];
    }

    private function __construct()
    {
    }
}
