<?php

declare(strict_types=1);

namespace ThreadSafe\Http;

/**
 * The outcome of a request including the redirects that were followed.
 */
final readonly class HttpResult
{
    /**
     * @param int                   $status    status of the last response, 0 if there was none
     * @param array<string, string> $headers   headers of the last response, names in lower case
     * @param list<string>          $redirects the URLs visited before the last one
     * @param string|null           $problem   why the request ended early: no answer, redirect loop, too many redirects, redirect to another host
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public string $url,
        public array $redirects = [],
        public ?string $problem = null,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function ok(): bool
    {
        return $this->problem === null && $this->status === 200;
    }
}
