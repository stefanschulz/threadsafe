<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use ThreadSafe\Remote\AgentResponse;
use ThreadSafe\Remote\AgentTransport;

/**
 * Wraps a real transport and keeps what was asked and answered, for the tests that talk to a real web server: the action of each call, the status, the `Range` that was
 * sent, how long it took. It can end the next download after some bytes, as a connection that breaks off does (the receiver is told to stop; what arrived stays).
 */
final class RecordingTransport implements AgentTransport
{
    /** @var list<array{action: string, method: string, status: int, range: string|null, seconds: float, error: string|null}> */
    public array $calls = [];

    /** End the next download that goes to a sink after this many bytes. */
    public ?int $cutNextDownloadAfter = null;

    public function __construct(private readonly AgentTransport $inner)
    {
    }

    public function send(string $method, string $url, array $headers = [], string $body = '', ?callable $sink = null, int $timeout = 60): AgentResponse
    {
        if ($sink !== null && $this->cutNextDownloadAfter !== null) {
            $limit = $this->cutNextDownloadAfter;
            $this->cutNextDownloadAfter = null;
            $seen = 0;
            $inner = $sink;
            $sink = static function (int $status, string $chunk) use ($inner, $limit, &$seen): ?bool {
                $answer = $inner($status, $chunk);
                $seen += strlen($chunk);

                return $seen >= $limit ? false : $answer;
            };
        }
        $response = $this->inner->send($method, $url, $headers, $body, $sink, $timeout);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $api = $query['api'] ?? '';
        $this->calls[] = [
            'action' => is_string($api) ? $api : '',
            'method' => $method,
            'status' => $response->status,
            'range' => $headers['Range'] ?? null,
            'seconds' => $response->seconds,
            'error' => $response->error,
        ];

        return $response;
    }

    /** @return list<array{action: string, method: string, status: int, range: string|null, seconds: float, error: string|null}> */
    public function of(string $action): array
    {
        return array_values(array_filter($this->calls, static fn (array $call): bool => $call['action'] === $action));
    }
}
