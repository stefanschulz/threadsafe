<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9;

/**
 * Result of parsing a parameters file: the entries of the inner `parameters` array and the positions needed to
 * insert further entries.
 *
 * @internal
 */
final readonly class ParsedParameters
{
    /**
     * @param list<ParameterEntry> $entries        in source order (a repeated key is listed repeatedly; the last one wins in PHP)
     * @param int                  $closeOffset    byte offset of the bracket that closes the `parameters` array
     * @param int|null             $lastEntryEnd   byte offset behind the last entry's value, null for an empty array
     * @param bool                 $lastHasComma   whether a comma follows the last entry
     */
    public function __construct(
        public array $entries,
        public int $closeOffset,
        public ?int $lastEntryEnd,
        public bool $lastHasComma,
    ) {
    }
}
