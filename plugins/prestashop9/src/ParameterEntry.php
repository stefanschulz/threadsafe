<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9;

/**
 * One `'key' => value` entry of the parameters array together with the byte span of its value expression in
 * the source text.
 *
 * @internal
 */
final readonly class ParameterEntry
{
    public function __construct(
        public string $key,
        public mixed $value,
        public int $valueStart,
        public int $valueEnd,
    ) {
    }
}
