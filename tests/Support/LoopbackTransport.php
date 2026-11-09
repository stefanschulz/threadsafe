<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use Closure;
use ThreadSafe\Remote\AgentResponse;
use ThreadSafe\Remote\AgentTransport;
use ThreadSafe\Web\Agent;
use ThreadSafe\Web\Request;
use ThreadSafe\Web\Response;

/**
 * A transport that asks an {@see Agent} in the same process, the way the web server of a site would hand the request over: the same path and query, the headers
 * in lower case, the body. For tests of the central side without a web server and without a network. It can cut a download after some bytes, as a connection
 * that breaks off does.
 */
final class LoopbackTransport implements AgentTransport
{
    /** @var list<array{method: string, uri: string, headers: array<string, string>, status: int}> what was asked and answered, in order */
    public array $log = [];

    /** Cut the next download (an answer that goes to a sink) after this many bytes: it ends with an error and status 0. */
    public ?int $cutNextDownloadAfter = null;

    /** @var (Closure(string): ?AgentResponse)|null called with the action of each request; an answer that it returns is used instead of the agent's */
    public ?Closure $intercept = null;

    public function __construct(private readonly Agent $agent, private readonly bool $https = true, private readonly string $address = '203.0.113.5')
    {
    }

    public function send(string $method, string $url, array $headers = [], string $body = '', ?callable $sink = null, int $timeout = 60): AgentResponse
    {
        $parts = parse_url($url);
        $path = (string) ($parts['path'] ?? '/');
        $queryString = (string) ($parts['query'] ?? '');
        parse_str($queryString, $parsed);
        $query = [];
        foreach ($parsed as $key => $value) {
            if (is_string($value)) {
                $query[(string) $key] = $value;
            }
        }
        $uri = $path . ($queryString !== '' ? '?' . $queryString : '');
        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower($name)] = $value;
        }

        if ($this->intercept !== null && ($answer = ($this->intercept)((string) ($query['api'] ?? ''))) !== null) {
            $this->log[] = ['method' => $method, 'uri' => $uri, 'headers' => $lower, 'status' => $answer->status];

            return $answer;
        }

        $response = $this->agent->handle(new Request($method, $query, [], [], $this->https, $this->address, $path, $lower, [], $uri, $body));
        $content = $this->body($response);
        $this->log[] = ['method' => $method, 'uri' => $uri, 'headers' => $lower, 'status' => $response->status];
        $received = [];
        foreach ($response->headers as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $received[strtolower(trim($name))] = trim($value);
            }
        }

        if ($sink !== null && $response->status >= 200 && $response->status < 300) {
            if ($this->cutNextDownloadAfter !== null) {
                $cut = $this->cutNextDownloadAfter;
                $this->cutNextDownloadAfter = null;
                if ($cut > 0) {
                    $sink($response->status, substr($content, 0, $cut));
                }

                return new AgentResponse(0, $received, error: 'connection reset by peer');
            }
            foreach (str_split($content, 65536) as $chunk) {
                $sink($response->status, $chunk);
            }

            return new AgentResponse($response->status, $received);
        }

        return new AgentResponse($response->status, $received, $content);
    }

    private function body(Response $response): string
    {
        if ($response->stream === null) {
            return $response->body;
        }
        ob_start();
        ($response->stream)();

        return (string) ob_get_clean();
    }
}
