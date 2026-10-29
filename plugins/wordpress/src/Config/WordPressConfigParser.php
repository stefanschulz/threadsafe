<?php

declare(strict_types=1);

namespace ThreadSafe\WordPress\Config;

/**
 * Reads a `wp-config.php` with PHP's tokenizer: the file is never included, so nothing in it runs.
 *
 * What is looked for is what WordPress and its tools write: the calls `define( 'NAME', value )` and the assignment `$table_prefix = value;`,
 * at the top of the file and inside blocks (`if ( ! defined( 'WP_DEBUG' ) ) { define( 'WP_DEBUG', false ); }`, `WP_HOME` in an `if`).
 * A value is understood when it is a single literal: a string without interpolation, a number (with a sign), `true`, `false` or `null`.
 * Anything else - a call such as the `getenv_docker( 'WORDPRESS_DB_NAME', 'wordpress' )` of the Docker image, a constant, a concatenation like
 * `__DIR__ . '/'` - is recorded as not understood and left alone; it only becomes an error when somebody asks for it. The rest of the file (the
 * functions, the conditions, the `require_once`) is skipped, not run.
 *
 * @internal
 */
final class WordPressConfigParser
{
    /** @var list<array{0: int|string, 1: string, 2: int, 3: int}> token id (or the character), text, offset, brace depth */
    private array $tokens = [];

    private function __construct()
    {
    }

    public static function parse(string $source): ParsedConfig
    {
        return (new self())->run($source);
    }

    private function run(string $source): ParsedConfig
    {
        $this->tokens = [];
        $offset = 0;
        $depth = 0;
        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $kind = is_array($token) ? $token[0] : $token;
            if ($kind === '}') {
                $depth = max(0, $depth - 1);
            }
            if (!is_array($token) || !in_array($kind, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML], true)) {
                $this->tokens[] = [$kind, $text, $offset, $depth];
            }
            if ($kind === '{' || $kind === T_CURLY_OPEN || $kind === T_DOLLAR_OPEN_CURLY_BRACES) {
                ++$depth;
            }
            $offset += strlen($text);
        }

        $entries = [];
        $count = count($this->tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $this->tokens[$i];
            if ($token[0] === T_STRING && strcasecmp($token[1], 'define') === 0 && ($this->tokens[$i + 1][0] ?? null) === '(' && !$this->isMemberOrDeclaration($i)) {
                $entry = $this->define($i);
                if ($entry !== null) {
                    $entries[] = $entry;
                }
            } elseif ($token[0] === T_VARIABLE && $token[1] === '$table_prefix' && ($this->tokens[$i + 1][0] ?? null) === '=' && $this->startsStatement($i)) {
                $entries[] = $this->assignment($i);
            }
        }

        return new ParsedConfig($entries);
    }

    /** `$object->define(`, `Class::define(` and `function define(` are not the call of the language function. */
    private function isMemberOrDeclaration(int $i): bool
    {
        $before = $this->tokens[$i - 1][0] ?? null;

        return in_array($before, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true);
    }

    private function startsStatement(int $i): bool
    {
        $before = $this->tokens[$i - 1][0] ?? null;

        return $before === null || $before === ';' || $before === '{' || $before === '}';
    }

    /** The call `define( 'NAME', value ... )` whose name token is at $i; null if the name is not a string literal. */
    private function define(int $i): ?ConfigEntry
    {
        $name = $this->tokens[$i + 2] ?? null;
        if ($name === null || $name[0] !== T_CONSTANT_ENCAPSED_STRING || ($this->tokens[$i + 3][0] ?? null) !== ',') {
            return null;
        }
        $first = $i + 4;
        $last = $this->endOfExpression($first, [',', ')']);

        return $this->entry(self::decodeString($name[1]), ConfigEntry::DEFINE, $first, $last, $name[3]);
    }

    private function assignment(int $i): ConfigEntry
    {
        $first = $i + 2;
        $last = $this->endOfExpression($first, [';']);

        return $this->entry('table_prefix', ConfigEntry::VARIABLE, $first, $last, $this->tokens[$i][3]);
    }

    /**
     * The index of the last token of the expression that begins at $first and ends in front of one of the characters at the depth where it
     * begins (a comma of an argument list, the closing parenthesis of the call, the semicolon of a statement).
     *
     * @param list<string> $enders
     */
    private function endOfExpression(int $first, array $enders): int
    {
        $nesting = 0;
        $count = count($this->tokens);
        for ($j = $first; $j < $count; ++$j) {
            $kind = $this->tokens[$j][0];
            if ($nesting === 0 && in_array($kind, $enders, true)) {
                return $j - 1;
            }
            if ($kind === '(' || $kind === '[' || $kind === '{' || $kind === T_CURLY_OPEN || $kind === T_DOLLAR_OPEN_CURLY_BRACES) {
                ++$nesting;
            } elseif ($kind === ')' || $kind === ']' || $kind === '}') {
                --$nesting;
                if ($nesting < 0) {
                    return $j - 1;
                }
            }
        }

        return $count - 1;
    }

    private function entry(string $name, string $kind, int $first, int $last, int $depth): ConfigEntry
    {
        $start = $this->tokens[$first][2] ?? 0;
        $end = $last >= $first ? $this->tokens[$last][2] + strlen($this->tokens[$last][1]) : $start;
        $literal = $last >= $first ? $this->literal($first, $last) : false;
        if ($literal !== false) {
            return new ConfigEntry($name, $kind, $literal[0], true, null, $depth, $start, $end);
        }
        $computedBy = $last >= $first && $this->tokens[$first][0] === T_STRING && ($this->tokens[$first + 1][0] ?? null) === '(' ? $this->tokens[$first][1] : null;

        return new ConfigEntry($name, $kind, null, false, $computedBy, $depth, $start, $end);
    }

    /**
     * The value of the tokens $first to $last if they are one literal: wrapped in an array (so that null can be told from "no literal"),
     * or false.
     *
     * @return array{0: mixed}|false
     */
    private function literal(int $first, int $last): array|false
    {
        $token = $this->tokens[$first];
        $length = $last - $first + 1;
        if ($length === 1) {
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                return [self::decodeString($token[1])];
            }
            if ($token[0] === T_LNUMBER || $token[0] === T_DNUMBER) {
                return [self::number($token)];
            }
            if ($token[0] === T_STRING) {
                return match (strtolower($token[1])) {
                    'true' => [true],
                    'false' => [false],
                    'null' => [null],
                    default => false,
                };
            }

            return false;
        }
        if ($length === 2 && ($token[0] === '-' || $token[0] === '+') && in_array($this->tokens[$first + 1][0], [T_LNUMBER, T_DNUMBER], true)) {
            $number = self::number($this->tokens[$first + 1]);

            return [$token[0] === '-' ? -$number : $number];
        }

        return false;
    }

    /** @param array{0: int|string, 1: string, 2: int, 3: int} $token */
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
