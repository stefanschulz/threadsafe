<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

/**
 * Temporary directories for tests, removed again after each test.
 *
 * Use in a TestCase and call {@see cleanUpTempDirectories()} from tearDown().
 */
trait TempDirectory
{
    /** @var list<string> */
    private array $tempDirectories = [];

    private function tempDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/threadsafe-test-' . bin2hex(random_bytes(6));
        mkdir($directory, 0777, true);
        $this->tempDirectories[] = $directory;

        return $directory;
    }

    private function cleanUpTempDirectories(): void
    {
        foreach ($this->tempDirectories as $directory) {
            $this->removeRecursively($directory);
        }
        $this->tempDirectories = [];
    }

    private function removeRecursively(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeRecursively($path . '/' . $entry);
                }
            }
            @rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            @unlink($path);
        }
    }
}
