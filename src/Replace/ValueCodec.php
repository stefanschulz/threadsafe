<?php

declare(strict_types=1);

namespace ThreadSafe\Replace;

use Closure;

/**
 * A structured text format (serialized PHP, JSON, …) in which strings can be rewritten without damaging the
 * structure: the codec walks the format, hands every string to a callback and rebuilds the value itself
 * (e.g. recalculating length prefixes).
 */
interface ValueCodec
{
    /** Cheap check whether the value is probably in this format. */
    public function looks(string $value): bool;

    /**
     * Whether a value that looks like this format but fails to parse may be treated as plain text. False for formats
     * with length prefixes (serialized PHP): a blind text replacement would corrupt them.
     */
    public function fallsBackToText(): bool;

    /**
     * @param Closure(string): string $rewriteString rewrites one string found inside the structure
     *
     * @return string|null the rebuilt value, or null if the value is not valid in this format
     */
    public function rewrite(string $value, Closure $rewriteString): ?string;
}
