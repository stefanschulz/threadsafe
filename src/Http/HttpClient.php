<?php

declare(strict_types=1);

namespace ThreadSafe\Http;

use Closure;

/**
 * A small HTTP GET client for the self-test: sends a chosen Host header (the shop answers only for its own domain, also
 * when the tool reaches it through another address), follows redirects on the shop's own host, and reports a redirect
 * loop or a redirect to a foreign host as a problem instead of following it.
 *
 * Uses PHP's stream wrapper, so it needs `allow_url_fopen`.
 */
final class HttpClient
{
    private const array REDIRECTS = [301, 302, 303, 307, 308];

    /** @var Closure(string, string): (array{int, array<string, string>, string}|null) */
    private readonly Closure $transport;

    /** Why the last request of the stream transport got no answer (PHP's message), for the problem text. */
    private ?string $failure = null;

    /**
     * @param bool         $insecure  do not verify TLS certificates (dev copies with a self-signed certificate)
     * @param Closure|null $transport for tests: fn (string $url, string $hostHeader): ?array{int, array<string, string>, string}
     */
    public function __construct(
        private readonly bool $insecure = false,
        private readonly int $timeoutSeconds = 30,
        private readonly int $maxRedirects = 10,
        ?Closure $transport = null,
    ) {
        $this->transport = $transport ?? $this->streamTransport(...);
    }

    /**
     * @param string $url        where to connect, e.g. "http://localhost:8290/" or "http://target-prestashop/"
     * @param string $hostHeader the shop's domain, sent as Host header (with port if it is not the default one)
     * @param bool   $pinned     the URL is a connection address that differs from the shop's domain: redirects to the
     *                           shop's domain are followed through that address
     */
    public function get(string $url, string $hostHeader, bool $pinned = false): HttpResult
    {
        $base = $this->origin($url);
        $shopHost = $this->hostName($hostHeader);
        $current = $url;
        $host = $hostHeader;
        $visited = [];

        for ($hop = 0; $hop <= $this->maxRedirects; ++$hop) {
            if (in_array($current, $visited, true)) {
                return new HttpResult(0, [], '', $current, $visited, 'redirect loop: ' . implode(' -> ', [...$visited, $current]));
            }
            $visited[] = $current;

            $response = ($this->transport)($current, $host);
            if ($response === null) {
                return new HttpResult(0, [], '', $current, array_slice($visited, 0, -1), "no answer from $current" . ($this->failure !== null ? " ({$this->failure})" : ''));
            }
            [$status, $headers, $body] = $response;

            $location = $headers['location'] ?? null;
            if (!in_array($status, self::REDIRECTS, true) || $location === null) {
                return new HttpResult($status, $headers, $body, $current, array_slice($visited, 0, -1));
            }

            $next = $this->follow($location, $current, $host, $base, $shopHost, $pinned);
            if (is_string($next)) {
                return new HttpResult($status, $headers, $body, $current, array_slice($visited, 0, -1), "redirect to another host: $next");
            }
            [$current, $host] = $next;
        }

        return new HttpResult(0, [], '', $current, $visited, 'too many redirects: ' . implode(' -> ', array_slice($visited, 0, 4)) . ' ...');
    }

    /**
     * @return array{string, string}|string the next URL and Host header, or the Location if it leads away from the shop
     */
    private function follow(string $location, string $current, string $host, string $base, string $shopHost, bool $pinned): array|string
    {
        if (str_starts_with($location, '//') || preg_match('~^[a-z][a-z0-9+.-]*://~i', $location) === 1) {
            $absolute = str_starts_with($location, '//') ? (parse_url($current, PHP_URL_SCHEME) ?: 'http') . ':' . $location : $location;
            $parts = parse_url($absolute);
            if (!is_array($parts) || !isset($parts['host']) || strcasecmp($parts['host'], $shopHost) !== 0) {
                return $location;
            }
            $authority = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

            return $pinned ? [$base . $path, $host] : [$absolute, $authority];
        }

        if (str_starts_with($location, '/')) {
            return [$this->origin($current) . $location, $host];
        }
        $directory = preg_replace('~[^/]*(\?.*)?$~', '', (string) parse_url($current, PHP_URL_PATH)) ?? '/';

        return [$this->origin($current) . $directory . $location, $host];
    }

    /** "scheme://host[:port]" of a URL. */
    private function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private function hostName(string $hostHeader): string
    {
        $colon = strrpos($hostHeader, ':');

        return $colon !== false && ctype_digit(substr($hostHeader, $colon + 1)) ? substr($hostHeader, 0, $colon) : $hostHeader;
    }

    /**
     * @return array{int, array<string, string>, string}|null
     */
    private function streamTransport(string $url, string $hostHeader): ?array
    {
        $options = [
            'method' => 'GET',
            'header' => "Host: $hostHeader\r\nUser-Agent: ThreadSafe-SelfTest\r\nAccept: */*\r\n",
            'follow_location' => 0,
            'ignore_errors' => true,
            'timeout' => $this->timeoutSeconds,
        ];
        $context = stream_context_create([
            'http' => $options,
            'ssl' => ['verify_peer' => !$this->insecure, 'verify_peer_name' => !$this->insecure],
        ]);

        error_clear_last();
        $body = @file_get_contents($url, false, $context);
        $responseHeaders = http_get_last_response_headers();
        if ($body === false || $responseHeaders === null) {
            // PHP's own reason (connection refused, timed out, certificate not trusted ...), without the function and the address it repeats
            $message = error_get_last()['message'] ?? '';
            $this->failure = $message === '' ? null : trim(preg_replace('~^[^:(]*\([^)]*\):\s*~', '', $message) ?? $message);

            return null;
        }
        $this->failure = null;

        $status = 0;
        $headers = [];
        foreach ($responseHeaders as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m) === 1) {
                $status = (int) $m[1];
                $headers = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return $status === 0 ? null : [$status, $headers, $body];
    }
}
