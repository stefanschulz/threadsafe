<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use Closure;
use ThreadSafe\Cli\Command;
use ThreadSafe\Cli\Input;
use ThreadSafe\Cli\OptionSpec;
use ThreadSafe\Cli\Output;
use ThreadSafe\Core\ExitCode;

/**
 * Command whose behaviour is a closure; for application tests.
 */
final class FakeCommand implements Command
{
    /**
     * @param Closure(Input, Output): ExitCode $run
     */
    public function __construct(
        private readonly string $name,
        private readonly Closure $run,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function summary(): string
    {
        return "Summary of $this->name";
    }

    public function usage(): string
    {
        return "$this->name <argument> [options]";
    }

    public function options(): array
    {
        return [new OptionSpec('root', 'Root directory', takesValue: true, valueName: 'PATH')];
    }

    public function run(Input $input, Output $output): ExitCode
    {
        return ($this->run)($input, $output);
    }
}
