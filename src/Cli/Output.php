<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

/**
 * Where a command writes to: normal output on stdout, problems on stderr.
 */
interface Output
{
    /** Writes to stdout, unless quiet. */
    public function writeln(string $line = ''): void;

    /** Writes to stderr (always shown, also when quiet). */
    public function error(string $line): void;

    /** Writes to stdout only in verbose mode. */
    public function verbose(string $line): void;

    public function isVerbose(): bool;

    public function isQuiet(): bool;
}
