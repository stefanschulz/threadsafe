<?php

declare(strict_types=1);

namespace ThreadSafe\Replace;

/**
 * What is known about one column before it is rewritten.
 */
final readonly class ColumnTreatment
{
    /**
     * @param string|null $form `plain`, `serialized` or `json`: only that structured format is tried; null = detect
     * @param bool        $bare the column holds bare host names, which are replaced too
     */
    public function __construct(public ?string $form = null, public bool $bare = false)
    {
    }
}
