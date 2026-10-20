<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

/**
 * Info-ZIP's `unzip` as an independent judge of the archives ThreadSafe writes (installed in the tools container
 * and on the CI runner; tests that need it are skipped where it is missing).
 */
final class UnzipOracle
{
    public static function available(): bool
    {
        $output = [];
        exec('unzip -v 2>&1', $output, $code);

        return $code === 0 && $output !== [];
    }

    /**
     * Runs `unzip -t` on the file.
     *
     * @return array{bool, string} whether it found no errors, and its output
     */
    public static function test(string $path): array
    {
        $output = [];
        exec('unzip -t ' . escapeshellarg($path) . ' 2>&1', $output, $code);

        return [$code === 0, implode("\n", $output)];
    }

    /**
     * Extracts into a directory (names are shown with #U escapes if no UTF-8 locale is set, which is harmless).
     */
    public static function extract(string $path, string $directory): bool
    {
        exec('LC_ALL=C.UTF-8 unzip -q -o ' . escapeshellarg($path) . ' -d ' . escapeshellarg($directory) . ' 2>&1', $output, $code);

        return $code === 0;
    }

    private function __construct()
    {
    }
}
