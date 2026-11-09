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
     * @param array<string, string> $headers the request headers, names in lower case ("x-csrf-token")
     * @param array<string, array{name: string, tmp_name: string, size: int, error: int}> $files the files of a multipart form, one per field
     * @param string                $uri     the path and the query as the client sent them (what a signature covers)
     * @param string                $body    the raw body of a request that is not a multipart form (at most {@see MAX_BODY} bytes; empty otherwise)
     */
    public function __construct(
        public string $method = 'GET',
        public array $query = [],
        public array $post = [],
        public array $cookies = [],
        public bool $https = false,
        public string $remoteAddress = '',
        public string $path = '',
        public array $headers = [],
        public array $files = [],
        public string $uri = '',
        public string $body = '',
    ) {
    }

    /** The most of a raw body that is read: the wizards send small forms, the agent small JSON. */
    public const int MAX_BODY = 1048576;

    public static function fromGlobals(): self
    {
        $strings = static fn (array $values): array => array_filter($values, 'is_string');
        $server = $_SERVER;
        $https = (isset($server['HTTPS']) && $server['HTTPS'] !== '' && strtolower((string) $server['HTTPS']) !== 'off')
            || (string) ($server['SERVER_PORT'] ?? '') === '443';

        $headers = [];
        foreach ($server as $name => $value) {
            if (is_string($value) && str_starts_with($name, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
            }
        }

        return new self(
            strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')),
            $strings($_GET),
            $strings($_POST),
            $strings($_COOKIE),
            $https,
            (string) ($server['REMOTE_ADDR'] ?? ''),
            (string) (parse_url((string) ($server['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? ''),
            $headers,
            self::uploads($_FILES),
            (string) ($server['REQUEST_URI'] ?? ''),
            self::rawBody($server),
        );
    }

    /**
     * The raw body of a request, if it has one worth reading: not a multipart form (php://input is empty for it anyway, and a restore may upload a
     * large part), and not more than {@see MAX_BODY}.
     *
     * @param array<mixed> $server
     */
    private static function rawBody(array $server): string
    {
        if (strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            return '';
        }
        $type = strtolower((string) ($server['CONTENT_TYPE'] ?? ''));
        $length = (int) ($server['CONTENT_LENGTH'] ?? 0);
        if (str_starts_with($type, 'multipart/') || $length > self::MAX_BODY) {
            return '';
        }
        $body = @file_get_contents('php://input', false, null, 0, self::MAX_BODY);

        return $body === false ? '' : $body;
    }

    /**
     * The single files of $_FILES (a field that holds several files is dropped, as arrays are everywhere else).
     *
     * @param array<mixed> $files
     *
     * @return array<string, array{name: string, tmp_name: string, size: int, error: int}>
     */
    private static function uploads(array $files): array
    {
        $uploads = [];
        foreach ($files as $field => $file) {
            if (is_array($file) && is_string($file['name'] ?? null) && is_string($file['tmp_name'] ?? null) && is_int($file['size'] ?? null) && is_int($file['error'] ?? null)) {
                $uploads[(string) $field] = ['name' => $file['name'], 'tmp_name' => $file['tmp_name'], 'size' => $file['size'], 'error' => $file['error']];
            }
        }

        return $uploads;
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

    /** A request header by its name in lower case, or null. */
    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    /** Whether the request comes from the machine itself (a tunnel, a local test server). */
    public function isLocal(): bool
    {
        return in_array($this->remoteAddress, ['127.0.0.1', '::1'], true);
    }
}
