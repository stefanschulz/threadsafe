<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

use ThreadSafe\Core\Logger;

/**
 * Sends the progress messages of a run to the terminal: details only in verbose mode, problems always.
 */
final class OutputLogger implements Logger
{
    public function __construct(private readonly Output $output)
    {
    }

    public function debug(string $message): void
    {
        $this->output->verbose('  [debug] ' . $message);
    }

    public function info(string $message): void
    {
        $this->output->verbose('  ' . $message);
    }

    public function warning(string $message): void
    {
        $this->output->writeln('  warning: ' . $message);
    }

    public function error(string $message): void
    {
        $this->output->error($message);
    }
}
