<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

/**
 * Output that collects everything in memory; used by tests.
 */
final class BufferedOutput implements Output
{
    private string $stdout = '';

    private string $stderr = '';

    public function __construct(
        private readonly bool $verbose = false,
        private readonly bool $quiet = false,
    ) {
    }

    public function writeln(string $line = ''): void
    {
        if (!$this->quiet) {
            $this->stdout .= $line . "\n";
        }
    }

    public function error(string $line): void
    {
        $this->stderr .= $line . "\n";
    }

    public function verbose(string $line): void
    {
        if ($this->verbose && !$this->quiet) {
            $this->stdout .= $line . "\n";
        }
    }

    public function isVerbose(): bool
    {
        return $this->verbose;
    }

    public function isQuiet(): bool
    {
        return $this->quiet;
    }

    public function stdout(): string
    {
        return $this->stdout;
    }

    public function stderr(): string
    {
        return $this->stderr;
    }
}
