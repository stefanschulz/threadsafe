<?php

declare(strict_types=1);

namespace ThreadSafe\WordPress\Config;

/**
 * One setting of a `wp-config.php`: a call `define( 'NAME', value )` or the assignment `$table_prefix = value;`, with the byte span of its
 * value in the source text.
 *
 * @internal
 */
final readonly class ConfigEntry
{
    public const string DEFINE = 'define';

    public const string VARIABLE = 'variable';

    /**
     * @param string      $name       the name of the constant, or `table_prefix` for the variable
     * @param string      $kind       {@see DEFINE} or {@see VARIABLE}
     * @param bool        $understood false if the value is more than a literal (a function call such as `getenv_docker(...)`, a constant, a
     *                                concatenation, a string with interpolation): it is then left alone and `$value` is null
     * @param string|null $computedBy for a value that is not understood: the name of the function it starts with, if it is a call
     * @param int         $depth      how many braces the statement is inside (0 at the top of the file; a `define` in an `if` block is 1)
     */
    public function __construct(
        public string $name,
        public string $kind,
        public mixed $value,
        public bool $understood,
        public ?string $computedBy,
        public int $depth,
        public int $valueStart,
        public int $valueEnd,
    ) {
    }
}
