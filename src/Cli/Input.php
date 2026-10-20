<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

/**
 * Parsed command line of one command.
 *
 * Accepted: `--flag`, `--name=value` (the value is always attached with "="), the short flags declared in the
 * option specs (`-h`), arguments, and `--` to end option parsing. Secrets must never be passed as option values
 * (shell history, process list); commands read them from files or the environment.
 */
final class Input
{
    /**
     * @param list<string>                $arguments
     * @param array<string, list<string>> $options   option name → given values (flags: one empty string per occurrence)
     */
    private function __construct(
        private readonly array $arguments,
        private readonly array $options,
    ) {
    }

    /**
     * @param list<string>     $tokens the command line without program name and command name
     * @param list<OptionSpec> $specs
     *
     * @throws UsageError
     */
    public static function parse(array $tokens, array $specs): self
    {
        $byName = [];
        $byShort = [];
        foreach ($specs as $spec) {
            $byName[$spec->name] = $spec;
            if ($spec->short !== null) {
                $byShort[$spec->short] = $spec;
            }
        }

        $arguments = [];
        $options = [];
        $optionsEnded = false;

        foreach ($tokens as $token) {
            if ($optionsEnded || $token === '-' || !str_starts_with($token, '-')) {
                $arguments[] = $token;
                continue;
            }
            if ($token === '--') {
                $optionsEnded = true;
                continue;
            }

            if (str_starts_with($token, '--')) {
                $body = substr($token, 2);
                $hasValue = str_contains($body, '=');
                [$name, $value] = $hasValue ? explode('=', $body, 2) : [$body, ''];
                $spec = $byName[$name] ?? throw new UsageError("Unknown option --$name.");
            } else {
                $name = substr($token, 1);
                $spec = $byShort[$name] ?? throw new UsageError("Unknown option -$name.");
                $name = $spec->name;
                $hasValue = false;
                $value = '';
            }

            if ($spec->takesValue && !$hasValue) {
                throw new UsageError("Option --$name needs a value: --$name={$spec->valueName}.");
            }
            if (!$spec->takesValue && $hasValue) {
                throw new UsageError("Option --$name does not take a value.");
            }
            if (!$spec->repeatable && isset($options[$name])) {
                throw new UsageError("Option --$name may be given only once.");
            }
            $options[$name][] = $value;
        }

        return new self($arguments, $options);
    }

    /** @return list<string> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function argument(int $index): ?string
    {
        return $this->arguments[$index] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->options[$name]);
    }

    /** The (last) value of an option with a value, null if not given. */
    public function value(string $name): ?string
    {
        $values = $this->options[$name] ?? [];

        return $values === [] ? null : $values[array_key_last($values)];
    }

    /** @return list<string> */
    public function values(string $name): array
    {
        return $this->options[$name] ?? [];
    }
}
