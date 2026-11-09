<?php

declare(strict_types=1);

namespace ThreadSafe\Remote;

/**
 * What the agent (or the web server in front of it) answered: the status, the headers (names in lower case), the body of an answer that was not
 * streamed into a file, and, if there was no answer at all, why.
 */
final readonly class AgentResponse
{
    /**
     * @param array<string, string> $headers
     * @param string|null           $error   set when nothing was answered: the connection failed, timed out or broke off (status 0)
     * @param bool                  $stopped the receiver of the body stopped the transfer on purpose (its time was up): what arrived is good, the rest is not there
     */
    public function __construct(
        public int $status,
        public array $headers = [],
        public string $body = '',
        public ?string $error = null,
        public float $seconds = 0.0,
        public bool $stopped = false,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
