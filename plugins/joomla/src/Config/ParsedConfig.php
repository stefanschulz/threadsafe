<?php

declare(strict_types=1);

namespace ThreadSafe\Joomla\Config;

/**
 * What {@see JoomlaConfigParser} found: the properties of `JConfig` in the order of the file, and where the class ends.
 *
 * @internal
 */
final readonly class ParsedConfig
{
    /**
     * @param list<ConfigEntry> $entries
     * @param int               $closeOffset the offset of the `}` that closes the class
     * @param int|null          $lastEnd     the offset behind the last property (its `;`), null if there is none
     */
    public function __construct(
        public array $entries,
        public int $closeOffset,
        public ?int $lastEnd,
    ) {
    }
}
