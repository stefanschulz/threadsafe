<?php

declare(strict_types=1);

namespace ThreadSafe\Joomla\Config;

use ThreadSafe\Joomla\Exception\InvalidConfigFile;

/**
 * Reads the class `JConfig` of a Joomla `configuration.php` with PHP's tokenizer: the file is never included, so nothing in it runs.
 *
 * What is understood is what Joomla writes: `public $name = <literal>;` with a literal that is a string without interpolation, a number
 * (with a sign), `true`, `false`, `null`, or an array of such literals. A property with anything else as its value (a constant, a
 * concatenation, a heredoc, a function call) is recorded as not understood and left alone; it only becomes an error when somebody asks
 * for it. Code outside the class is skipped, not run.
 *
 * @internal
 */
final class JoomlaConfigParser
{
    /** @var list<array{0: int|string, 1: string, 2: int}> token id (or the character), text, offset */
    private array $tokens = [];

    private int $index = 0;

    private function __construct()
    {
    }

    /**
     * @throws InvalidConfigFile there is no class JConfig, or it is not closed
     */
    public static function parse(string $source): ParsedConfig
    {
        return (new self())->run($source);
    }

    private function run(string $source): ParsedConfig
    {
        $this->tokens = [];
        $offset = 0;
        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $this->tokens[] = [is_array($token) ? $token[0] : $token, $text, $offset];
            }
            $offset += strlen($text);
        }

        $open = $this->findClass();
        $this->index = $open + 1;
        $entries = [];
        $lastEnd = null;
        while (($token = $this->tokens[$this->index] ?? null) !== null) {
            if ($token[0] === '}') {
                return new ParsedConfig($entries, $token[2], $lastEnd);
            }
            if (in_array($token[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR, T_STATIC, T_READONLY], true)) {
                $entry = $this->property();
                if ($entry !== null) {
                    $entries[] = $entry;
                    $lastEnd = $this->tokens[$this->index - 1][2] + 1;
                }

                continue;
            }
            $this->skipStatement();
        }

        throw new InvalidConfigFile('The class JConfig is not closed.');
    }

    /** @return int the index of the `{` of `class JConfig` */
    private function findClass(): int
    {
        $count = count($this->tokens);
        for ($i = 0; $i < $count; ++$i) {
            if ($this->tokens[$i][0] === T_CLASS && ($this->tokens[$i + 1][0] ?? null) === T_STRING && strcasecmp($this->tokens[$i + 1][1], 'JConfig') === 0) {
                for ($j = $i + 2; $j < $count; ++$j) {
                    if ($this->tokens[$j][0] === '{') {
                        return $j;
                    }
                }
            }
        }

        throw new InvalidConfigFile('The file has no class JConfig.');
    }

    /** One property declaration, from its modifiers to its `;`; null if it is not a property (a method, a constant). */
    private function property(): ?ConfigEntry
    {
        $i = $this->index;
        while (in_array($this->tokens[$i][0] ?? null, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR, T_STATIC, T_READONLY], true)) {
            ++$i;
        }
        // a type in front of the name (`public string $x`; Joomla writes none, a site's own code might)
        while (in_array($this->tokens[$i][0] ?? null, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR, T_ARRAY, '?', '|'], true)) {
            ++$i;
        }
        $name = $this->tokens[$i] ?? null;
        if ($name === null || $name[0] !== T_VARIABLE) {
            $this->skipStatement();

            return null;
        }
        $nameEnd = $name[2] + strlen($name[1]);
        $key = substr($name[1], 1);
        $this->index = $i + 1;

        $next = $this->tokens[$this->index] ?? null;
        if ($next !== null && $next[0] === ';') {
            ++$this->index;

            return new ConfigEntry($key, null, true, $nameEnd, $nameEnd, $nameEnd);
        }
        if ($next === null || $next[0] !== '=') {
            $this->skipStatement();

            return new ConfigEntry($key, null, false, $nameEnd, $nameEnd, $nameEnd);
        }

        ++$this->index;
        $start = $this->tokens[$this->index][2] ?? $nameEnd;
        $mark = $this->index;
        $value = $this->value();
        $end = $this->tokens[$this->index - 1][2] + strlen($this->tokens[$this->index - 1][1]);
        if ($value !== false && $this->kind() === ';') {
            ++$this->index;

            return new ConfigEntry($key, $value[0], true, $nameEnd, $start, $end);
        }

        // more than a literal: go to the end of the statement and say that this one is not understood
        $this->index = $mark;
        $this->skipStatement();
        $last = $this->tokens[$this->index - 2] ?? $name;

        return new ConfigEntry($key, null, false, $nameEnd, $start, $last[2] + strlen($last[1]));
    }

    /**
     * A literal: the value wrapped in an array (so that null can be told from "no literal"), or false if what follows is not one.
     *
     * @return array{0: mixed}|false
     */
    private function value(): array|false
    {
        $token = $this->tokens[$this->index] ?? null;
        if ($token === null) {
            return false;
        }

        if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
            ++$this->index;

            return [self::decodeString($token[1])];
        }
        if ($token[0] === T_LNUMBER || $token[0] === T_DNUMBER) {
            ++$this->index;

            return [self::number($token)];
        }
        if (($token[0] === '-' || $token[0] === '+') && in_array($this->tokens[$this->index + 1][0] ?? null, [T_LNUMBER, T_DNUMBER], true)) {
            $number = self::number($this->tokens[$this->index + 1]);
            $this->index += 2;

            return [$token[0] === '-' ? -$number : $number];
        }
        if ($token[0] === T_STRING) {
            $literal = match (strtolower($token[1])) {
                'true' => [true],
                'false' => [false],
                'null' => [null],
                default => false,
            };
            if ($literal !== false) {
                ++$this->index;
            }

            return $literal;
        }
        if ($token[0] === T_ARRAY || $token[0] === '[') {
            return $this->arrayValue();
        }

        return false;
    }

    /** @return array{0: array<int|string, mixed>}|false */
    private function arrayValue(): array|false
    {
        $opener = $this->tokens[$this->index];
        $closer = $opener[0] === T_ARRAY ? ')' : ']';
        $this->index += $opener[0] === T_ARRAY ? 2 : 1;
        if ($opener[0] === T_ARRAY && ($this->tokens[$this->index - 1][0] ?? null) !== '(') {
            return false;
        }

        $items = [];
        $next = 0;
        while (($token = $this->tokens[$this->index] ?? null) !== null) {
            if ($token[0] === $closer) {
                ++$this->index;

                return [$items];
            }
            $first = $this->value();
            if ($first === false) {
                return false;
            }
            $key = null;
            $value = $first;
            if ($this->kind() === T_DOUBLE_ARROW) {
                ++$this->index;
                $key = $first[0];
                $value = $this->value();
                if ($value === false || !(is_int($key) || is_string($key))) {
                    return false;
                }
            }
            if ($key === null) {
                $items[$next++] = $value[0];
            } else {
                $items[$key] = $value[0];
                if (is_int($key) && $key >= $next) {
                    $next = $key + 1;
                }
            }
            if ($this->kind() === ',') {
                ++$this->index;
            }
        }

        return false;
    }

    /** The kind of the token at the position (its id, or the character), null behind the end. */
    private function kind(): int|string|null
    {
        return $this->tokens[$this->index][0] ?? null;
    }

    /** Moves behind the next `;` at the depth of the statement, over braces, brackets and parentheses. */
    private function skipStatement(): void
    {
        $depth = 0;
        while (($token = $this->tokens[$this->index] ?? null) !== null) {
            ++$this->index;
            if ($token[0] === '{' || $token[0] === '(' || $token[0] === '[' || $token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                ++$depth;
            } elseif ($token[0] === '}' || $token[0] === ')' || $token[0] === ']') {
                --$depth;
                if ($depth < 0) {
                    --$this->index;   // the end of the class: not ours to take

                    return;
                }
                if ($depth === 0 && $token[0] === '}') {
                    return;   // a method or a constant list ends with its brace
                }
            } elseif ($token[0] === ';' && $depth === 0) {
                return;
            }
        }
    }

    /** @param array{0: int|string, 1: string, 2: int} $token */
    private static function number(array $token): int|float
    {
        $text = str_replace('_', '', $token[1]);

        return $token[0] === T_LNUMBER ? intval($text, 0) : (float) $text;
    }

    private static function decodeString(string $literal): string
    {
        $body = substr($literal, 1, -1);
        if ($literal[0] === "'") {
            return strtr($body, ['\\\\' => '\\', "\\'" => "'"]);
        }

        return (string) preg_replace_callback(
            '/\\\\(?:([\\\\"$nrtvef])|([0-7]{1,3})|x([0-9A-Fa-f]{1,2})|u\{([0-9A-Fa-f]+)\})/',
            static function (array $m): string {
                if ($m[1] !== '') {
                    return match ($m[1]) {
                        'n' => "\n",
                        'r' => "\r",
                        't' => "\t",
                        'v' => "\v",
                        'e' => "\e",
                        'f' => "\f",
                        default => $m[1],
                    };
                }
                if ($m[2] !== '') {
                    return chr(octdec($m[2]) % 256);
                }
                if ($m[3] !== '') {
                    return chr((int) hexdec($m[3]));
                }

                return mb_chr((int) hexdec($m[4]), 'UTF-8');
            },
            $body,
        );
    }
}
