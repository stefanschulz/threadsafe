<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9;

/**
 * Result of {@see PhpIniRewriter::rewrite()}.
 */
final readonly class PhpIniRewrite
{
    /**
     * @param list<array{directive: string, before: string, after: string}> $changes
     * @param list<string>                                                  $warnings
     */
    public function __construct(
        public string $content,
        public array $changes,
        public array $warnings,
    ) {
    }
}
