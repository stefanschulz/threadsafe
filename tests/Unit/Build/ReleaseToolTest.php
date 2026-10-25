<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Build;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Build\RestoreBuilder;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The release as the workflow makes it: `tools/build-release.php` from this tree, then `tools/test-release.php` on the files it wrote, in
 * a process that has nothing of the repository's `vendor/`. This is the proof that a tag gives a release that works.
 */
final class ReleaseToolTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @return array{int, string}
     */
    private function shell(string $command): array
    {
        $output = [];
        exec($command . ' 2>&1', $output, $code);

        return [$code, implode("\n", $output)];
    }

    public function testTheBuiltReleasePassesItsOwnTest(): void
    {
        if (!is_file(dirname(__DIR__, 3) . '/vendor/autoload.php') || trim((string) shell_exec('command -v composer')) === '' || trim((string) shell_exec('command -v unzip')) === '') {
            self::markTestSkipped('The build needs Composer and the test needs unzip.');
        }
        $root = dirname(__DIR__, 3);
        $out = $this->tempDirectory() . '/release';

        [$code, $built] = $this->shell(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/build-release.php') . ' --version=v1.2.3-rc.1 --output=' . escapeshellarg($out));
        self::assertSame(0, $code, $built);
        self::assertStringContainsString('threadsafe-1.2.3-rc.1.zip', $built);
        foreach (['threadsafe-1.2.3-rc.1.zip', 'install.php', 'restore.php', 'SHA256SUMS'] as $file) {
            self::assertFileExists($out . '/' . $file);
        }

        // the test script is copied away from the repository, as the workflow runs it on a runner that has only that file
        $script = $this->tempDirectory() . '/test-release.php';
        copy($root . '/tools/test-release.php', $script);
        [$code, $tested] = $this->shell(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($out) . ' v1.2.3-rc.1');
        self::assertSame(0, $code, $tested);
        self::assertStringContainsString('The release is in order.', $tested);
        self::assertStringNotContainsString('FAILED', $tested);
    }

    public function testAVersionThatIsNoVersionIsRefusedBeforeAnythingIsBuilt(): void
    {
        [$code, $output] = $this->shell(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/tools/build-release.php') . ' --version=latest --output=' . escapeshellarg($this->tempDirectory()));

        self::assertSame(1, $code);
        self::assertStringContainsString('not a valid version', $output);
    }

    public function testTheComposerOptionIsACommandLineAsWellAsAFile(): void
    {
        // a command line with a space in it is used as it is (a quoted "php composer.phar" would not be a command); its failure is reported
        [$code, $output] = $this->shell(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/tools/build-release.php') . ' --version=1.0.0 --output=' . escapeshellarg($this->tempDirectory()) . ' ' . escapeshellarg('--composer=' . PHP_BINARY . ' -r "exit(3);" --'));

        self::assertSame(1, $code);
        self::assertStringContainsString('Composer failed (exit 3)', $output);
    }
    public function testAnUnknownArgumentIsRefused(): void
    {
        [$code, $output] = $this->shell(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/tools/build-release.php') . ' --publish');

        self::assertSame(1, $code);
        self::assertStringContainsString('unknown argument', $output);
    }

    public function testTheRestoreToolOfAReleaseSaysTheVersionOfTheRelease(): void
    {
        $out = $this->tempDirectory() . '/restore.php';

        (new RestoreBuilder(dirname(__DIR__, 3), '9.8.7'))->build($out);

        self::assertStringContainsString('// ThreadSafe restore tool 9.8.7 -', (string) file_get_contents($out, false, null, 0, 120));
    }
}
