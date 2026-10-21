<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use RuntimeException;

/**
 * A route exists, but not for the method of the request (a GET for something that changes state, for example).
 */
final class MethodNotAllowed extends RuntimeException
{
    /**
     * @param list<string> $allowed the methods the route has
     */
    public function __construct(public readonly array $allowed)
    {
        parent::__construct('Method not allowed; use ' . implode(' or ', $allowed) . '.');
    }
}
