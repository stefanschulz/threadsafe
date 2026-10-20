<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\Exception\SelfTestFailed;
use ThreadSafe\Core\Exception\StepFailed;
use ThreadSafe\Core\ExitCode;
use ThreadSafe\Core\Version;
use Throwable;

/**
 * Command line entry point: finds the command, parses its options and turns failures into exit codes
 * (see {@see ExitCode}).
 */
final class Application
{
    private const string NAME = 'threadsafe';

    /** @var array<string, Command> */
    private array $commands = [];

    /**
     * @param list<Command> $commands
     */
    public function __construct(array $commands)
    {
        foreach ($commands as $command) {
            $this->commands[$command->name()] = $command;
        }
        ksort($this->commands);
    }

    /**
     * @param list<string> $argv the full argument vector, program name first
     */
    public function run(array $argv, Output $output): int
    {
        $tokens = array_slice($argv, 1);
        $first = $tokens[0] ?? null;

        if ($first === null || $first === 'list' || $first === '--help' || $first === '-h') {
            $this->printOverview($output);

            return ExitCode::Ok->value;
        }
        if ($first === '--version' || $first === '-V') {
            $output->writeln(self::NAME . ' ' . Version::CURRENT);

            return ExitCode::Ok->value;
        }
        if ($first === 'help') {
            return $this->help($tokens[1] ?? null, $output)->value;
        }

        $command = $this->commands[$first] ?? null;
        if ($command === null) {
            return $this->usageError("Unknown command \"$first\".", $output)->value;
        }

        try {
            $input = Input::parse(array_slice($tokens, 1), array_merge($command->options(), self::globalOptions()));
            if ($input->has('help')) {
                $this->printCommandHelp($command, $output);

                return ExitCode::Ok->value;
            }
            if ($input->has('verbose') || $input->has('quiet')) {
                $output = $this->withMode($output, $input->has('verbose'), $input->has('quiet'));
            }

            return $command->run($input, $output)->value;
        } catch (UsageError $e) {
            $output->error('error: ' . $e->getMessage());
            $output->error('Run "' . self::NAME . ' help ' . $command->name() . '" for usage.');

            return ExitCode::Usage->value;
        } catch (GuardFailed $e) {
            $output->error('Refused: ' . $e->getMessage());

            return ExitCode::GuardFailed->value;
        } catch (StepFailed $e) {
            $output->error('error: ' . $e->getMessage());

            return ($e instanceof SelfTestFailed ? ExitCode::SelfTestFailed : ExitCode::StepFailed)->value;
        } catch (Throwable $e) {
            $output->error('Internal error: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $output->error($e->getTraceAsString());
            }

            return ExitCode::Internal->value;
        }
    }

    /** @return list<OptionSpec> */
    private static function globalOptions(): array
    {
        return [
            new OptionSpec('help', 'Show help for this command', short: 'h'),
            new OptionSpec('verbose', 'Show details', short: 'v'),
            new OptionSpec('quiet', 'Show only errors', short: 'q'),
        ];
    }

    private function withMode(Output $output, bool $verbose, bool $quiet): Output
    {
        return $output instanceof StreamOutput ? $output->withMode($verbose, $quiet) : $output;
    }

    private function help(?string $name, Output $output): ExitCode
    {
        if ($name === null) {
            $this->printOverview($output);

            return ExitCode::Ok;
        }
        $command = $this->commands[$name] ?? null;
        if ($command === null) {
            return $this->usageError("Unknown command \"$name\".", $output);
        }
        $this->printCommandHelp($command, $output);

        return ExitCode::Ok;
    }

    private function usageError(string $message, Output $output): ExitCode
    {
        $output->error('error: ' . $message);
        $output->error('Run "' . self::NAME . ' list" for the available commands.');

        return ExitCode::Usage;
    }

    private function printOverview(Output $output): void
    {
        $output->writeln(self::NAME . ' ' . Version::CURRENT . ' – backup and migration tool for PHP web applications');
        $output->writeln();
        $output->writeln('Usage:');
        $output->writeln('  ' . self::NAME . ' <command> [options]');
        $output->writeln();
        $output->writeln('Commands:');
        $width = max([4, ...array_map('strlen', array_keys($this->commands))]);
        $output->writeln('  ' . str_pad('help', $width) . '  Show help for a command');
        foreach ($this->commands as $name => $command) {
            $output->writeln('  ' . str_pad($name, $width) . '  ' . $command->summary());
        }
        $output->writeln();
        $output->writeln('Global options:');
        foreach (self::globalOptions() as $option) {
            $output->writeln('  ' . str_pad($option->synopsis(), 16) . $option->description);
        }
        $output->writeln('  ' . str_pad('-V, --version', 16) . 'Show the version');
        $output->writeln();
        $output->writeln('Exit codes: 0 ok, 1 usage error, 2 refused (guard), 3 step failed, 4 self-test failed, 5 internal error.');
    }

    private function printCommandHelp(Command $command, Output $output): void
    {
        $output->writeln($command->summary());
        $output->writeln();
        $output->writeln('Usage:');
        $output->writeln('  ' . self::NAME . ' ' . $command->usage());
        $output->writeln();
        $output->writeln('Options:');
        foreach (array_merge($command->options(), self::globalOptions()) as $option) {
            $output->writeln('  ' . str_pad($option->synopsis(), 30) . $option->description);
        }
    }
}
