<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

/**
 * Result of a plain HTTP request made by the integration tests.
 */
final readonly class HttpResponse
{
    /**
     * @param array<string, string> $headers lower-case header name → last value
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
