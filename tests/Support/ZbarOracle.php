<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

/**
 * `zbarimg` (the package zbar-tools of the tools image) as the oracle of the QR codes: a reader that is not ours. The picture is written
 * as a PBM image with the quiet zone of four modules, each module a square of 8 pixels, which ImageMagick (the image library of zbar) reads.
 */
final class ZbarOracle
{
    public static function available(): bool
    {
        exec('zbarimg --version 2>&1', $output, $code);

        return $code === 0;
    }

    /**
     * What zbar reads from a picture.
     *
     * @param array<int, array<int, bool>> $modules  the modules, without the quiet zone
     * @param string                       $directory an existing directory for the image file
     *
     * @return string|null the text, or null if zbar found no code
     */
    public static function read(array $modules, string $directory): ?string
    {
        $file = $directory . '/code-' . bin2hex(random_bytes(4)) . '.pbm';
        file_put_contents($file, self::pbm($modules));
        // the standard error is left out: zbar writes a complaint about a missing message bus there, which has nothing to do with the picture
        exec('zbarimg --quiet --raw ' . escapeshellarg($file) . ' 2>/dev/null', $output, $code);
        @unlink($file);

        return $code === 0 ? implode("\n", $output) : null;
    }

    /**
     * The modules that an SVG of {@see \ThreadSafe\Auth\QrCode::svg()} draws, read back from its path (so that what is tested is the picture
     * that a page delivers, not the matrix it was made from).
     *
     * @return array<int, array<int, bool>>
     */
    public static function modulesOfSvg(string $svg): array
    {
        if (preg_match('/viewBox="0 0 (\d+) \d+"/', $svg, $box) !== 1 || preg_match('/ d="([^"]*)"/', $svg, $path) !== 1) {
            return [];
        }
        $size = (int) $box[1] - 8;
        $modules = array_fill(0, $size, array_fill(0, $size, false));
        preg_match_all('/M(\d+) (\d+)h(\d+)v1h-\d+z/', $path[1], $runs, PREG_SET_ORDER);
        foreach ($runs as [, $x, $y, $length]) {
            for ($i = 0; $i < (int) $length; ++$i) {
                $modules[(int) $y - 4][(int) $x - 4 + $i] = true;
            }
        }

        return $modules;
    }

    /**
     * @param array<int, array<int, bool>> $modules
     */
    private static function pbm(array $modules, int $scale = 8, int $quiet = 4): string
    {
        $size = count($modules) + 2 * $quiet;
        $lines = [];
        for ($y = 0; $y < $size; ++$y) {
            $row = '';
            for ($x = 0; $x < $size; ++$x) {
                $dark = ($modules[$y - $quiet][$x - $quiet] ?? false) === true;
                $row .= str_repeat($dark ? '1 ' : '0 ', $scale);
            }
            for ($i = 0; $i < $scale; ++$i) {
                $lines[] = $row;
            }
        }

        return "P1\n" . ($size * $scale) . ' ' . ($size * $scale) . "\n" . implode("\n", $lines) . "\n";
    }
}
