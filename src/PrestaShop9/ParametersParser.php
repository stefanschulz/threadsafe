<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9;

use ThreadSafe\PrestaShop9\Exception\InvalidParametersFile;

/**
 * Reads `app/config/parameters.php` of PrestaShop without executing it.
 *
 * The file is lexed with PHP's own tokenizer and then parsed by a tiny grammar that accepts exactly what
 * `var_export()` produces (and a bit more): `<?php return array(...);` containing arrays, quoted strings (also
 * joined with `.`), integers, floats, `true`, `false` and `null`. Anything else – variables, function calls,
 * constants, operators, a second statement – is rejected, so reading the file can never run code.
 *
 * @internal
 */
final class ParametersParser
{
    /** @var list<array{0: int|string, 1: string, 2: int, 3: int}> significant tokens: id or character, text, byte offset, line */
    private array $tokens = [];

    private int $index = 0;

    /** Byte offset behind the most recently consumed token. */
    private int $lastEnd = 0;

    /** @var array{items: list<array{key: int|string, value: mixed, start: int, end: int}>, close: int, lastEnd: int|null, lastComma: bool}|null */
    private ?array $inner = null;

    private function __construct()
    {
    }

    public static function parse(string $source): ParsedParameters
    {
        return (new self())->run($source);
    }

    private function run(string $source): ParsedParameters
    {
        $offset = 0;
        foreach (token_get_all($source) as $token) {
            $id = is_array($token) ? $token[0] : $token;
            $text = is_array($token) ? $token[1] : $token;
            $line = is_array($token) ? $token[2] : 0;
            if (!in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $this->tokens[] = [$id, $text, $offset, $line];
            }
            $offset += strlen($text);
        }

        $this->expect(T_OPEN_TAG, 'The file must start with "<?php".');
        $this->expect(T_RETURN, 'Expected "return".');
        $opener = $this->peek();
        if ($opener === null || !$this->isArrayStart($opener)) {
            $this->fail('Expected an array after "return"', $opener);
        }
        $this->consume();
        $this->parseArray(0, $this->closerFor($opener));
        $this->expect(';', 'Expected ";" after the array.');

        $rest = $this->peek();
        if ($rest !== null && $rest[0] === T_CLOSE_TAG) {
            $this->consume();
            $rest = $this->peek();
            if ($rest !== null && $rest[0] === T_INLINE_HTML && trim($rest[1]) === '') {
                $this->consume();
                $rest = $this->peek();
            }
        }
        if ($rest !== null) {
            $this->fail('Unexpected code after the returned array', $rest);
        }
        if ($this->inner === null) {
            throw new InvalidParametersFile('The file has no "parameters" array.');
        }

        $entries = [];
        foreach ($this->inner['items'] as $item) {
            $entries[] = new ParameterEntry((string) $item['key'], $item['value'], $item['start'], $item['end']);
        }

        return new ParsedParameters($entries, $this->inner['close'], $this->inner['lastEnd'], $this->inner['lastComma']);
    }

    /**
     * Parses array entries up to the closing bracket (the opening bracket is already consumed).
     *
     * @return array{items: list<array{key: int|string, value: mixed, start: int, end: int}>, close: int, lastEnd: int|null, lastComma: bool}
     */
    private function parseArray(int $depth, string $closer): array
    {
        $items = [];
        $nextIndex = 0;
        $lastEnd = null;
        $lastComma = false;
        $close = 0;

        while (true) {
            $token = $this->peek();
            if ($token === null) {
                throw new InvalidParametersFile('Unterminated array.');
            }
            if ($token[0] === $closer) {
                $close = $token[2];
                $this->consume();
                break;
            }

            $start = $token[2];
            $key = $nextIndex;
            $value = $this->parseValue($depth);
            $next = $this->peek();
            if ($next !== null && $next[0] === T_DOUBLE_ARROW) {
                if (!is_string($value) && !is_int($value)) {
                    $this->fail('Only string and integer array keys are supported', $next);
                }
                $key = $this->normaliseKey($value);
                $this->consume();
                $valueToken = $this->peek();
                $start = $valueToken === null ? $start : $valueToken[2];
                if ($depth === 0 && $key === 'parameters' && $valueToken !== null && $this->isArrayStart($valueToken)) {
                    $this->consume();
                    $inner = $this->parseArray(1, $this->closerFor($valueToken));
                    $this->inner = $inner;
                    $value = $this->flatten($inner['items']);
                } else {
                    $value = $this->parseValue($depth);
                }
            }
            if (is_int($key) && $key >= $nextIndex) {
                $nextIndex = $key + 1;
            }
            $items[] = ['key' => $key, 'value' => $value, 'start' => $start, 'end' => $this->lastEnd];
            $lastEnd = $this->lastEnd;

            $after = $this->peek();
            $lastComma = $after !== null && $after[0] === ',';
            if ($lastComma) {
                $this->consume();
            } elseif ($after === null || $after[0] !== $closer) {
                $this->fail('Expected "," or the end of the array', $after);
            }
        }

        return ['items' => $items, 'close' => $close, 'lastEnd' => $lastEnd, 'lastComma' => $lastComma];
    }

    private function parseValue(int $depth): mixed
    {
        $token = $this->peek();
        if ($token === null) {
            throw new InvalidParametersFile('Unexpected end of file.');
        }

        if ($this->isArrayStart($token)) {
            $this->consume();
            $node = $this->parseArray($depth + 1, $this->closerFor($token));

            return $this->flatten($node['items']);
        }

        switch ($token[0]) {
            case T_CONSTANT_ENCAPSED_STRING:
                $this->consume();
                $string = $this->decodeString($token[1]);
                while (($dot = $this->peek()) !== null && $dot[0] === '.') {
                    $this->consume();
                    $part = $this->peek();
                    if ($part === null || $part[0] !== T_CONSTANT_ENCAPSED_STRING) {
                        $this->fail('Only string literals can be joined with "."', $part);
                    }
                    $this->consume();
                    $string .= $this->decodeString($part[1]);
                }

                return $string;

            case '-':
            case '+':
                $this->consume();
                $number = $this->peek();
                if ($number === null || ($number[0] !== T_LNUMBER && $number[0] !== T_DNUMBER)) {
                    $this->fail('Expected a number after the sign', $number);
                }
                $this->consume();
                $value = $this->number($number);

                return $token[0] === '-' ? -$value : $value;

            case T_LNUMBER:
            case T_DNUMBER:
                $this->consume();

                return $this->number($token);

            case T_STRING:
                $this->consume();

                return match (strtolower($token[1])) {
                    'true' => true,
                    'false' => false,
                    'null' => null,
                    default => $this->fail('Constants are not allowed', $token),
                };

            default:
                $this->fail('Unsupported expression', $token);
        }
    }

    /**
     * @param list<array{key: int|string, value: mixed, start: int, end: int}> $items
     *
     * @return array<int|string, mixed>
     */
    private function flatten(array $items): array
    {
        $array = [];
        foreach ($items as $item) {
            $array[$item['key']] = $item['value'];
        }

        return $array;
    }

    private function normaliseKey(int|string $key): int|string
    {
        // PHP turns decimal-integer strings into integer keys.
        if (is_string($key) && preg_match('/^(?:0|-?[1-9][0-9]*)$/', $key) === 1 && (string) (int) $key === $key) {
            return (int) $key;
        }

        return $key;
    }

    /**
     * @param array{0: int|string, 1: string, 2: int, 3: int} $token
     */
    private function number(array $token): int|float
    {
        $text = str_replace('_', '', $token[1]);

        return $token[0] === T_LNUMBER ? intval($text, 0) : (float) $text;
    }

    private function decodeString(string $literal): string
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

    /**
     * @return array{0: int|string, 1: string, 2: int, 3: int}|null
     */
    private function peek(): ?array
    {
        return $this->tokens[$this->index] ?? null;
    }

    private function consume(): void
    {
        $token = $this->tokens[$this->index];
        $this->lastEnd = $token[2] + strlen($token[1]);
        ++$this->index;
    }

    private function expect(int|string $id, string $message): void
    {
        $token = $this->peek();
        if ($token === null || $token[0] !== $id) {
            $this->fail($message, $token);
        }
        $this->consume();
    }

    /**
     * @param array{0: int|string, 1: string, 2: int, 3: int}|null $token
     */
    private function isArrayStart(?array $token): bool
    {
        return $token !== null && ($token[0] === '[' || ($token[0] === T_ARRAY && ($this->tokens[$this->index + 1][0] ?? null) === '('));
    }

    /**
     * Consumes the "(" of "array(" and returns the matching closing character; for "[" it returns "]".
     *
     * @param array{0: int|string, 1: string, 2: int, 3: int} $opener the token returned by peek() before consuming
     */
    private function closerFor(array $opener): string
    {
        if ($opener[0] === T_ARRAY) {
            // consume() already moved past "array"; the "(" is next.
            $this->consume();

            return ')';
        }

        return ']';
    }

    /**
     * @param array{0: int|string, 1: string, 2: int, 3: int}|null $token
     */
    private function fail(string $message, ?array $token): never
    {
        $where = $token === null ? ' at the end of the file' : ' on line ' . $token[3] . ' near "' . substr($token[1], 0, 30) . '"';

        throw new InvalidParametersFile($message . $where . '.');
    }
}
