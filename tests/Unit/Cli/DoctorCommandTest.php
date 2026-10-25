<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Build\ReleaseManifest;
use ThreadSafe\Cli\Application;
use ThreadSafe\Cli\BufferedOutput;
use ThreadSafe\Cli\DoctorCommand;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * `threadsafe doctor`: the list of checks on the command line, the summary, the exit code, and the check of a release.json.
 */
final class DoctorCommandTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function cli(string $data, array $arguments = []): array
    {
        $output = new BufferedOutput();
        $code = (new Application([new DoctorCommand($data)]))->run(['threadsafe', 'doctor', ...$arguments], $output);

        return [$code, $output->stdout(), $output->stderr()];
    }

    private function release(string $php = '8.4', string $extension = 'json'): string
    {
        $manifest = new ReleaseManifest('0.2.0', 1761955200, $php, ['required' => [$extension], 'oneOf' => [], 'recommended' => []], 1, ['a.php' => ['size' => 1, 'sha256' => str_repeat('a', 64)]]);
        $file = $this->tempDirectory() . '/release.json';
        file_put_contents($file, $manifest->toJson());

        return $file;
    }

    public function testItListsTheChecksAndSaysHowItWent(): void
    {
        [$code, $out] = $this->cli($this->tempDirectory() . '/data');

        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/^info +PHP: ' . preg_quote(PHP_VERSION, '/') . '/m', $out);
        self::assertStringContainsString('Installation: ThreadSafe', $out);
        self::assertStringContainsString('Data directory:', $out);
        self::assertMatchesRegularExpression('/(Everything is in order\.|Nothing stops ThreadSafe; \d+ warnings?\.)$/', trim($out));
    }

    public function testAProblemIsAFailureWithExitCodeTwo(): void
    {
        $file = $this->tempDirectory() . '/a-file';
        file_put_contents($file, 'x');

        [$code, $out] = $this->cli($file . '/data');

        self::assertSame(2, $code);
        self::assertMatchesRegularExpression('/^FAILED +Data directory: .* cannot be made/m', $out);
        self::assertStringContainsString('1 problem that must be fixed', $out);
    }

    public function testItChecksAHostAgainstTheReleaseItWouldInstall(): void
    {
        [$code, $out] = $this->cli($this->tempDirectory() . '/data', ['--release=' . $this->release()]);

        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/^ok +Version 0\.2\.0: This host meets its requirements/m', $out);
    }

    public function testAReleaseThatNeedsMoreThanTheHostHasIsAFailure(): void
    {
        [$code, $out] = $this->cli($this->tempDirectory() . '/data', ['--release=' . $this->release('99.0', 'no_such_extension')]);

        self::assertSame(2, $code);
        self::assertStringContainsString('needs PHP 99.0 or newer', $out);
        self::assertStringContainsString('needs the PHP extension no_such_extension', $out);
        self::assertStringContainsString('2 problems that must be fixed', $out);
    }

    public function testAFileThatIsNoReleaseJsonIsAUsageError(): void
    {
        $file = $this->tempDirectory() . '/other.json';
        file_put_contents($file, '{"hello": 1}');

        [$code, , $err] = $this->cli($this->tempDirectory() . '/data', ['--release=' . $file]);
        self::assertSame(1, $code);
        self::assertStringContainsString('is not a release.json', $err);

        [$code, , $err] = $this->cli($this->tempDirectory() . '/data', ['--release=/nowhere/release.json']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Cannot read', $err);
    }

    public function testAnArgumentIsRefused(): void
    {
        [$code, , $err] = $this->cli($this->tempDirectory() . '/data', ['now']);

        self::assertSame(1, $code);
        self::assertStringContainsString('Unexpected argument "now"', $err);
    }
}
