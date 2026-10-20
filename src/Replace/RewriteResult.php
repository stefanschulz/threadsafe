<?php

declare(strict_types=1);

namespace ThreadSafe\Replace;

/**
 * The outcome of rewriting one value.
 */
final readonly class RewriteResult
{
    public function __construct(
        public string $value,
        public bool $changed,
        public Stats $stats,
    ) {
    }
}
