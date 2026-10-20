<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

use ThreadSafe\Core\ExitCode;

/**
 * A subcommand of the `threadsafe` tool.
 */
interface Command
{
    /** Name typed on the command line, e.g. "migrate". */
    public function name(): string;

    /** One line for the command list. */
    public function summary(): string;

    /** Usage line without the program name, e.g. "migrate prestashop9 --root=PATH --to=DOMAIN [options]". */
    public function usage(): string;

    /** @return list<OptionSpec> */
    public function options(): array;

    /**
     * @throws UsageError                                  wrong arguments (exit code 1)
     * @throws \ThreadSafe\Core\Exception\GuardFailed      a precondition is not met (exit code 2)
     * @throws \ThreadSafe\Core\Exception\StepFailed       a step failed (exit codes 3 and 4)
     */
    public function run(Input $input, Output $output): ExitCode;
}
