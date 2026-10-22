<?php

declare(strict_types=1);

namespace ThreadSafe\App;

use Closure;
use ThreadSafe\Core\Paths;
use ThreadSafe\Core\PhpBinary;

/**
 * Starts `threadsafe cron` as a detached process from a web request (docs/PHASE5.md 3.3, "worker mode"), for a host whose cron can
 * only fetch an address. The process lives on after the request has ended (seen on the live host, section 8, finding 8).
 *
 * How it is started is fixed by what went wrong in the spike (finding 9): **a clean environment** (`env -i`: a child that inherits
 * the `REQUEST_METHOD` of the CGI request is taken for another request by the CGI binary and starts itself over and over), standard
 * input `/dev/null`, its output in a file under the data directory, `setsid` where it exists. It never starts a second process
 * within half a minute; the real guard against two workers is the lock `locks/cron.lock` that the worker takes.
 */
final class WorkerLauncher
{
    /** Seconds in which no second worker is started. */
    public const int PAUSE = 30;

    /** @var Closure(string): bool */
    private readonly Closure $shell;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param string|null             $php   the PHP program; default: {@see PhpBinary::cli()}
     * @param Closure(string): bool|null $shell runs a shell command, true if it was started (for tests)
     * @param Closure(): int|null     $clock the time in seconds (for tests)
     */
    public function __construct(private readonly string $data, private readonly ?string $php = null, ?Closure $shell = null, ?Closure $clock = null)
    {
        $this->shell = $shell ?? self::runShell(...);
        $this->clock = $clock ?? time(...);
    }

    /** Whether this host can start a detached PHP process at all. */
    public static function available(): bool
    {
        return DIRECTORY_SEPARATOR === '/' && PhpBinary::cli() !== null && (function_exists('exec') || function_exists('proc_open'));
    }

    /**
     * @return string|null null if a worker was started; otherwise why not
     */
    public function start(): ?string
    {
        $php = $this->php ?? PhpBinary::cli();
        if ($php === null || DIRECTORY_SEPARATOR !== '/') {
            return 'there is no PHP program that can be started from here';
        }
        $marker = $this->data . '/cron-spawned';
        $now = ($this->clock)();
        if (is_file($marker) && $now - (int) @filemtime($marker) < self::PAUSE) {
            return 'a worker was started a moment ago';
        }
        if (!is_dir($this->data) && !@mkdir($this->data, 0700, true) && !is_dir($this->data)) {
            return 'the data directory cannot be created';
        }
        @touch($marker, $now);

        if (!($this->shell)($this->command($php))) {
            return 'the process could not be started';
        }

        return null;
    }

    /** The shell command that starts the worker; public for the tests. */
    public function command(string $php): string
    {
        $setsid = self::hasSetsid() ? 'setsid ' : '';

        return 'env -i PATH=/usr/local/bin:/usr/bin:/bin THREADSAFE_DATA=' . escapeshellarg($this->data) . ' ' . $setsid . 'nohup ' . escapeshellarg($php) . ' -q '
            . escapeshellarg(Paths::program() . '/bin/threadsafe') . ' cron --quiet < /dev/null > ' . escapeshellarg($this->data . '/cron-worker.log') . ' 2>&1 &';
    }

    private static function hasSetsid(): bool
    {
        foreach (['/usr/bin/setsid', '/bin/setsid', '/usr/local/bin/setsid'] as $path) {
            if (@is_executable($path)) {
                return true;
            }
        }

        return false;
    }

    private static function runShell(string $command): bool
    {
        if (function_exists('exec')) {
            $output = [];
            $code = 1;
            @exec($command, $output, $code);

            return $code === 0;
        }
        if (function_exists('proc_open')) {
            $process = @proc_open(['/bin/sh', '-c', $command], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            if (!is_resource($process)) {
                return false;
            }

            return proc_close($process) === 0;
        }

        return false;
    }
}
