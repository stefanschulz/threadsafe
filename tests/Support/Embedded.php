<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

/**
 * Marks a spot in a generated structure where a string holding another encoded structure ("serialized" or "json")
 * is to be placed. Lets property tests build nested encodings and still compute the expected result.
 */
final readonly class Embedded
{
    /**
     * @param 'serialized'|'json' $kind
     */
    public function __construct(
        public string $kind,
        public mixed $spec,
    ) {
    }
}
