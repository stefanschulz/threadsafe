<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Build;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * `tools/build-restore.php`, the quick build of the restore tool: what it says about itself, and that an option is never taken for the name of the file
 * to write (field test: "--help" once wrote a 350 KB file of that name).
 */
final class BuildRestoreToolTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @return array{int, string}
     */
    private function tool(string $arguments): array
    {
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/tools/build-restore.php') . ' ' . $arguments . ' 2>&1', $output, $code);

        return [$code, implode("\n", $output)];
    }

    public function testHelpSaysWhereTheDevelopmentBuildGoesAndWhichFileToHandOut(): void
    {
        [$code, $output] = $this->tool('--help');

        self::assertSame(0, $code);
        self::assertStringContainsString('default: dist/dev/threadsafe-restore.php, a development build', $output);
        self::assertStringContainsString('dist/release/threadsafe-restore.php', $output);
    }

    public function testAnUnknownOptionIsAnErrorAndWritesNothing(): void
    {
        $directory = $this->tempDirectory();

        [$code, $output] = $this->tool('--bogus');

        self::assertSame(1, $code);
        self::assertStringContainsString('unknown option "--bogus"', $output);
        self::assertFileDoesNotExist(dirname(__DIR__, 3) . '/--bogus');
        self::assertSame([], glob($directory . '/*') ?: []);
    }

    public function testAnExplicitOutputIsWrittenAndCalledADevelopmentBuildOnlyByDefault(): void
    {
        $target = $this->tempDirectory() . '/threadsafe-restore.php';

        [$code, $output] = $this->tool(escapeshellarg($target));

        self::assertSame(0, $code, $output);
        self::assertFileExists($target);
        self::assertStringNotContainsString('development build (version', $output, 'a named file is not "the default build"');
        $page = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('$_SERVER["REQUEST_METHOD"] = "GET"; include $argv[1];') . ' ' . escapeshellarg($target));
        self::assertStringStartsWith('<!doctype html>', $page, 'the first page of the built tool, without a key file, is the designed one');
    }
}
