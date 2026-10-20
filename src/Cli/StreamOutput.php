<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

/**
 * Output to stdout/stderr (or any stream).
 */
final class StreamOutput implements Output
{
    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        private $stdout,
        private $stderr,
        private readonly bool $verbose = false,
        private readonly bool $quiet = false,
    ) {
    }

    /** Output to the real standard streams. */
    public static function standard(bool $verbose = false, bool $quiet = false): self
    {
        return new self(STDOUT, STDERR, $verbose, $quiet);
    }

    public function withMode(bool $verbose, bool $quiet): self
    {
        return new self($this->stdout, $this->stderr, $verbose, $quiet);
    }

    public function writeln(string $line = ''): void
    {
        if (!$this->quiet) {
            fwrite($this->stdout, $line . "\n");
        }
    }

    public function error(string $line): void
    {
        fwrite($this->stderr, $line . "\n");
    }

    public function verbose(string $line): void
    {
        if ($this->verbose && !$this->quiet) {
            fwrite($this->stdout, $line . "\n");
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
}
