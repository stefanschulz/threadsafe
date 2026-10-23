<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Core\Version;

/**
 * The real executable: arguments, output streams and exit codes as a shell sees them.
 */
final class BinTest extends TestCase
{
    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function execute(array $arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/bin/threadsafe', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    public function testVersion(): void
    {
        [$code, $stdout, $stderr] = $this->execute(['--version']);

        self::assertSame(0, $code);
        self::assertSame('threadsafe ' . Version::CURRENT . "\n", $stdout);
        self::assertSame('', $stderr);
    }

    public function testOverviewGoesToStdout(): void
    {
        [$code, $stdout] = $this->execute([]);

        self::assertSame(0, $code);
        self::assertStringContainsString('Usage:', $stdout);
    }

    public function testErrorsGoToStderrWithExitCodeOne(): void
    {
        [$code, $stdout, $stderr] = $this->execute(['no-such-command']);

        self::assertSame(1, $code);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Unknown command', $stderr);
    }
    /**
     * Shared hosting often offers PHP 8 only as the CGI binary (which also runs scripts from a shell): it has no STDERR
     * constant, a time limit of its own and prints HTML errors. `bin/threadsafe` must work with it, so that a cron job or a
     * shell on such a host can run backups.
     */
    public function testRunsWithThePhpCgiBinary(): void
    {
        $cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php-cgi' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        if (!is_file($cgi)) {
            self::markTestSkipped('There is no php-cgi next to the PHP binary.');
        }
        [$code, $stdout, $stderr] = $this->executeWithCgi($cgi, ['--version']);
        self::assertSame(0, $code, $stdout . $stderr);
        self::assertSame('threadsafe ' . Version::CURRENT . "\n", $stdout);

        [$code, $stdout] = $this->executeWithCgi($cgi, ['no-such-command']);
        self::assertSame(1, $code);
        self::assertSame('', $stdout);
    }

    /**
     * php.ini-production turns `register_argc_argv` off, and the CGI binary then has no `$argv` (the command line binary always has it): the
     * arguments must come from the process (found on the CI runner, whose PHP has that ini file).
     */
    public function testRunsWithThePhpCgiBinaryThatHandsOverNoArguments(): void
    {
        $cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php-cgi';
        if (!is_file($cgi) || !is_readable('/proc/self/cmdline')) {
            self::markTestSkipped('There is no php-cgi next to the PHP binary, or no /proc to read the command line from.');
        }

        [$code, $stdout, $stderr] = $this->executeWithCgi($cgi, ['--version'], ['register_argc_argv=0']);
        self::assertSame(0, $code, $stdout . $stderr);
        self::assertSame('threadsafe ' . Version::CURRENT . "\n", $stdout);

        [$code, $stdout, $stderr] = $this->executeWithCgi($cgi, ['no-such-command'], ['register_argc_argv=0']);
        self::assertSame(1, $code);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Unknown command', $stderr);
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $settings  more `-d` settings of the binary (`name=value`)
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function executeWithCgi(string $cgi, array $arguments, array $settings = []): array
    {
        $options = [];
        foreach (['max_execution_time=1', ...$settings] as $setting) {
            array_push($options, '-d', $setting);
        }
        $process = proc_open(
            [$cgi, '-q', ...$options, dirname(__DIR__, 3) . '/bin/threadsafe', ...$arguments],
            [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
