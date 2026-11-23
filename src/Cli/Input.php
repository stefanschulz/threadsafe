<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

/**
 * Parsed command line of one command.
 *
 * Accepted: `--flag`, `--name=value` (the value is always attached with "="), the short flags declared in the
 * option specs (`-h`), arguments, and `--` to end option parsing. Secrets must never be passed as option values
 * (shell history, process list); commands read them from files or the environment.
 *
 * On Windows a path whose backslashes a POSIX shell (Git Bash) has removed - `C:\sicher\key.txt` arrives as `C:sicherkey.txt`, which PHP reads as a
 * path relative to the current directory of drive C - is refused with a hint instead of being used (see {@see self::lostBackslashes()}).
 */
final class Input
{
    /** The value names of the options that take a path (the check for lost backslashes looks at these only). */
    private const array PATH_VALUES = ['FILE', 'DIR', 'PATH'];

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
     * @param bool|null        $windows whether to look for paths that lost their backslashes (default: the program runs on Windows)
     *
     * @throws UsageError
     */
    public static function parse(array $tokens, array $specs, ?bool $windows = null): self
    {
        $windows ??= DIRECTORY_SEPARATOR === '\\';
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
                if ($windows && self::lostBackslashes($token)) {
                    throw new UsageError(self::lostBackslashesMessage("The argument \"$token\""));
                }
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
            if ($windows && $spec->takesValue && in_array($spec->valueName, self::PATH_VALUES, true) && self::lostBackslashes($value)) {
                throw new UsageError(self::lostBackslashesMessage("The value of --$name (\"$value\")"));
            }
            $options[$name][] = $value;
        }

        return new self($arguments, $options);
    }

    /**
     * Whether a text has the shape of a Windows path whose backslashes are gone: a drive letter and a colon, then a name with no separator
     * (`C:sicherkey.txt`). On Windows that is a path relative to the current directory of the drive, which nobody means; it is what Git Bash makes of `C:\sicher\key.txt`.
     * A path that still has a backslash or a slash after the colon, a URL (`https://...`) and a lone drive (`C:`) are not meant.
     */
    public static function lostBackslashes(string $text): bool
    {
        return preg_match('~^[A-Za-z]:[^\\\\/]~', $text) === 1;
    }

    private static function lostBackslashesMessage(string $what): string
    {
        return $what . ' looks like a Windows path whose backslashes were removed by the shell (Git Bash treats \ as an escape character), so it would name another place than you meant. '
            . 'Put the path in quotes or write it with forward slashes, for example C:/sicher/key.txt.';
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
