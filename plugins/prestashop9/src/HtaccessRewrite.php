<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9;

/**
 * Result of {@see HtaccessRewriter::rewrite()}.
 */
final readonly class HtaccessRewrite
{
    /**
     * @param int          $changedLines number of lines that differ
     * @param list<string> $warnings     things the rewriter could not or would not handle
     */
    public function __construct(
        public string $content,
        public int $changedLines,
        public array $warnings,
    ) {
    }
}
