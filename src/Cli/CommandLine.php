<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

/**
 * The arguments of the running command line, also where PHP does not hand them over. The PHP CGI binary (often the only PHP 8 on a
 * shared host) sets `$argv` only if `register_argc_argv` is on, and `php.ini-production` turns it off; the command line binary is
 * not affected. Linux still knows the command line of the process, so the arguments are read from there when `$argv` is missing.
 */
final class CommandLine
{
    /**
     * @param array<array-key, mixed>|null $argv   `$argv` as PHP has it, if it has it
     * @param string                       $script the script that runs (`__FILE__` of the executable)
     *
     * @return list<string>|null the script and its arguments, or null if they cannot be found
     */
    public static function arguments(?array $argv, string $script, string $processFile = '/proc/self/cmdline', ?string $directory = null): ?array
    {
        if ($argv !== null) {
            return array_values(array_map('strval', $argv));
        }
        $raw = @file_get_contents($processFile);

        return is_string($raw) ? self::fromProcess($raw, $script, $directory ?? (getenv('PWD') ?: null)) : null;
    }

    /**
     * The script and what follows it in the command line of a process (the words are separated by NUL, as `/proc/<pid>/cmdline` has
     * them); what stands before the script - the binary and its options such as `-q` or `-d name=value` - is dropped. The CGI binary
     * changes into the directory of the script, so a relative path is looked up from the directory the shell was in (`$directory`, the
     * `PWD` of the environment); if that is not known either, the word that ends in the name of the script is taken.
     *
     * @return list<string>|null null if the script is not among the words
     */
    public static function fromProcess(string $commandLine, string $script, ?string $directory = null): ?array
    {
        $words = explode("\0", $commandLine);
        if (end($words) === '') {
            array_pop($words);
        }
        $wanted = realpath($script);
        foreach ($words as $index => $word) {
            if ($index === 0 || $word === '') {
                continue;
            }
            if ($word === $script || ($wanted !== false && (realpath($word) === $wanted || ($directory !== null && realpath($directory . '/' . $word) === $wanted)))) {
                return array_slice($words, $index);
            }
        }
        foreach ($words as $index => $word) {
            if ($index > 0 && basename($word) === basename($script)) {
                return array_slice($words, $index);
            }
        }

        return null;
    }

    private function __construct()
    {
    }
}
