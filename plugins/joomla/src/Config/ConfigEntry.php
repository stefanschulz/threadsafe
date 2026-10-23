<?php

declare(strict_types=1);

namespace ThreadSafe\Joomla\Config;

/**
 * One `public $name = value;` of the class `JConfig`, with the byte span of its value in the source text.
 *
 * @internal
 */
final readonly class ConfigEntry
{
    /**
     * @param bool $understood false if the value is more than a literal (a constant, a concatenation, a heredoc ...): it is then left
     *                         alone and `$value` is null
     * @param int  $nameEnd    the offset behind the name of the property
     */
    public function __construct(
        public string $name,
        public mixed $value,
        public bool $understood,
        public int $nameEnd,
        public int $valueStart,
        public int $valueEnd,
    ) {
    }
}
