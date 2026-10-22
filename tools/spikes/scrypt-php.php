<?php

/**
 * SPIKE (phase 5, WP 1a) - throw-away script, not part of the program.
 *
 * Question: `age` protects a passphrase with scrypt (N = 2^18, r = 8, p = 1 by default: 256 MB). Can scrypt be done in PHP, in the
 * memory and the time of a web request, at a work factor that is safe enough? And does an age file whose passphrase stanza we make
 * open with the real tool?
 *
 *   php tools/spikes/scrypt-php.php [highest log2(N), default 15]
 */
declare(strict_types=1);

function salsa8(array $b): array
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

/** @param list<int> $b 32*r words @return list<int> */
function blockMix(array $b, int $r): array
{
    $x = array_slice($b, (2 * $r - 1) * 16, 16);
    $even = [];
    $odd = [];
    for ($i = 0; $i < 2 * $r; ++$i) {
        $block = array_slice($b, $i * 16, 16);
        for ($k = 0; $k < 16; ++$k) {
            $x[$k] ^= $block[$k];
        }
        $x = salsa8($x);
        if ($i % 2 === 0) {
            array_push($even, ...$x);
        } else {
            array_push($odd, ...$x);
        }
    }

    return [...$even, ...$odd];
}

function scrypt(string $password, string $salt, int $n, int $r, int $p, int $length): string
{
    $words = 32 * $r;
    $b = hash_pbkdf2('sha256', $password, $salt, 1, $p * 128 * $r, true);
    for ($i = 0; $i < $p; ++$i) {
        $block = substr($b, $i * 128 * $r, 128 * $r);
        $x = array_values(unpack('V' . $words, $block) ?: []);
        $v = [];
        for ($j = 0; $j < $n; ++$j) {
            $v[$j] = pack('V*', ...$x);
            $x = blockMix($x, $r);
        }
        for ($j = 0; $j < $n; ++$j) {
            $k = $x[(2 * $r - 1) * 16] & ($n - 1);
            $other = array_values(unpack('V' . $words, $v[$k]) ?: []);
            for ($w = 0; $w < $words; ++$w) {
                $x[$w] ^= $other[$w];
            }
            $x = blockMix($x, $r);
        }
        $b = substr_replace($b, pack('V*', ...$x), $i * 128 * $r, 128 * $r);
    }

    return hash_pbkdf2('sha256', $password, $b, 1, $length, true);
}

echo 'PHP ', PHP_VERSION, "\n";
// RFC 7914 test vector 1
$got = bin2hex(scrypt('', '', 16, 1, 1, 64));
echo '1. RFC 7914 vector (N=16, r=1, p=1): ', $got === '77d6576238657b203b19ca42c18a0497f16b4844e3074ae8dfdffa3fede21442fcd0069ded0948f8326a753a0fc81f17e8d3e0fb2e0d3628cf35e20c38d18906' ? 'correct' : "WRONG ($got)", "\n";

echo "2. time and memory for r=8, p=1 (age uses r=8, p=1):\n";
$highest = (int) ($argv[1] ?? 15);
for ($log = 10; $log <= $highest; ++$log) {
    $before = memory_get_peak_usage(true);
    $t = microtime(true);
    scrypt('correct horse battery staple', 'salt', 1 << $log, 8, 1, 32);
    printf("   N = 2^%-2d  %7.2f s   peak memory %6.1f MB\n", $log, microtime(true) - $t, memory_get_peak_usage(true) / 1048576);
}
