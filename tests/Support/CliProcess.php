<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use RuntimeException;

/**
 * Runs `bin/threadsafe` as a real process, the way a shell does.
 */
final class CliProcess
{
    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    public static function run(array $arguments, int $timeoutSeconds = 300): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/threadsafe', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start bin/threadsafe.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = time() + $timeoutSeconds;
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (time() > $deadline) {
                proc_terminate($process);
                throw new RuntimeException('bin/threadsafe did not finish within ' . $timeoutSeconds . ' seconds.');
            }
            usleep(20000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [$status['exitcode'] >= 0 ? $status['exitcode'] : $exit, $stdout, $stderr];
    }
}
