<?php

declare(strict_types=1);

namespace ThreadSafe\Archive;

/**
 * What reading a whole archive found: how much was read and every problem (an empty list means intact).
 */
final readonly class VerifyResult
{
    /**
     * @param list<string> $problems
     */
    public function __construct(public int $entries, public int $bytes, public array $problems)
    {
    }

    public function ok(): bool
    {
        return $this->problems === [];
    }
}
