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
}
