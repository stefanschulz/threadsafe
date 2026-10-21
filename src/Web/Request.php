<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

/**
 * What the wizard needs to know about an HTTP request, as plain data (so that it can be tested without a web server).
 */
final readonly class Request
{
    /**
     * @param array<string, string> $query   the GET parameters
     * @param array<string, string> $post    the POST fields (arrays are not accepted anywhere in the wizard and are dropped)
     * @param array<string, string> $cookies
     */
    public function __construct(
        public string $method = 'GET',
        public array $query = [],
        public array $post = [],
        public array $cookies = [],
        public bool $https = false,
        public string $remoteAddress = '',
        public string $path = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $strings = static fn (array $values): array => array_filter($values, 'is_string');
        $server = $_SERVER;
        $https = (isset($server['HTTPS']) && $server['HTTPS'] !== '' && strtolower((string) $server['HTTPS']) !== 'off')
            || (string) ($server['SERVER_PORT'] ?? '') === '443';

        return new self(
            strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')),
            $strings($_GET),
            $strings($_POST),
            $strings($_COOKIE),
            $https,
            (string) ($server['REMOTE_ADDR'] ?? ''),
            (string) (parse_url((string) ($server['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? ''),
        );
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function query(string $name): ?string
    {
        return $this->query[$name] ?? null;
    }

    public function post(string $name): ?string
    {
        return $this->post[$name] ?? null;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /** Whether the request comes from the machine itself (a tunnel, a local test server). */
    public function isLocal(): bool
    {
        return in_array($this->remoteAddress, ['127.0.0.1', '::1'], true);
    }
}
