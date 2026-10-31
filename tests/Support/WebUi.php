<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use RuntimeException;

/**
 * A browser for the web interface of ThreadSafe behind a real web server (the `web` service of the Docker stack): real sockets, a
 * real cookie, the CSRF token of the last page. Requests are plain HTTP/1.0, so that the answer is not chunked and the end of the
 * body is the end of the connection; a download can be read only in part, as a connection that breaks.
 */
final class WebUi
{
    public string $cookie = '';

    public string $csrf = '';

    public function __construct(private readonly string $host = 'web', private readonly int $port = 80, private readonly string $base = '')
    {
    }

    /**
     * @param array<string, string> $fields  form fields of a POST
     * @param array<string, string> $headers
     * @param int|null              $maxBody read no more than this many bytes of the body, then drop the connection
     * @param (callable(string): void)|null $sink gets the body piece by piece instead of the answer holding it (a large download)
     *
     * @return array{status: int, headers: array<string, string>, body: string, cookies: list<string>}
     */
    public function request(string $method, string $path, array $fields = [], array $headers = [], ?int $maxBody = null, ?callable $sink = null): array
    {
        $connection = $this->connect();
        fwrite($connection, $this->message($method, $path, $fields, $headers));

        return $this->read($connection, $maxBody, $sink);
    }

    /**
     * The SHA-256 and the length of what a path delivers, without keeping it in memory.
     *
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, string>, sha256: string, bytes: int}
     */
    public function hashOf(string $path, array $headers = [], ?\HashContext $context = null): array
    {
        $context ??= hash_init('sha256');
        $bytes = 0;
        $answer = $this->request('GET', $path, [], $headers, null, static function (string $piece) use ($context, &$bytes): void {
            hash_update($context, $piece);
            $bytes += strlen($piece);
        });

        return ['status' => $answer['status'], 'headers' => $answer['headers'], 'sha256' => hash_final($context), 'bytes' => $bytes];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string, cookies: list<string>}
     */
    public function get(string $path, array $headers = []): array
    {
        return $this->request('GET', $path, [], $headers);
    }

    /**
     * A POST with the CSRF token of the last page.
     *
     * @param array<string, string> $fields
     *
     * @return array{status: int, headers: array<string, string>, body: string, cookies: list<string>}
     */
    public function post(string $path, array $fields = []): array
    {
        return $this->request('POST', $path, $fields + ['csrf' => $this->csrf]);
    }

    /**
     * Follows redirects (to the path the Location names).
     *
     * @param array{status: int, headers: array<string, string>, body: string, cookies: list<string>} $response
     *
     * @return array{status: int, headers: array<string, string>, body: string, cookies: list<string>}
     */
    public function follow(array $response): array
    {
        for ($i = 0; $i < 6 && $response['status'] === 303; ++$i) {
            $response = $this->get($response['headers']['location'] ?? '/');
        }

        return $response;
    }

    /**
     * Several requests at the same moment: all are sent before any answer is read.
     *
     * @param list<array{0: string, 1: string, 2?: array<string, string>, 3?: array<string, string>}> $requests method, path, fields, headers
     *
     * @return list<array{status: int, headers: array<string, string>, body: string, cookies: list<string>}>
     */
    public function parallel(array $requests, float $delay = 0.0): array
    {
        $connections = [];
        foreach ($requests as $request) {
            $connection = $this->connect();
            fwrite($connection, $this->message($request[0], $request[1], $request[2] ?? [], $request[3] ?? []));
            $connections[] = $connection;
            if ($delay > 0) {
                usleep((int) ($delay * 1000000));
            }
        }

        return array_map(fn ($connection): array => $this->read($connection, null), $connections);
    }

    /** Logs in and returns whether it worked. */
    public function login(string $password, string $user = 'admin'): bool
    {
        $this->get('/login');
        $response = $this->request('POST', '/login', ['user' => $user, 'password' => $password, 'plain' => '1']);
        if ($response['status'] !== 303) {
            return false;
        }
        $this->get('/');

        return true;
    }

    /**
     * The JSON a tick answers with (and the HTTP status as "http").
     *
     * @return array<string, mixed>
     */
    public function tick(string $path): array
    {
        $response = $this->request('POST', $path, [], ['X-CSRF-Token' => $this->csrf]);
        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            throw new RuntimeException('A tick answered with something that is not JSON (HTTP ' . $response['status'] . '): ' . substr($response['body'], 0, 300));
        }

        return $data + ['http' => $response['status']];
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     */
    private function message(string $method, string $path, array $fields, array $headers): string
    {
        $lines = [$method . ' ' . $this->base . $path . ' HTTP/1.0', 'Host: ' . $this->host . ($this->port === 80 ? '' : ':' . $this->port), 'Connection: close'];
        // a request may bring its own cookie (several logins asked at the same moment through one object)
        if ($this->cookie !== '' && !isset($headers['Cookie'])) {
            $lines[] = 'Cookie: threadsafe_session=' . $this->cookie;
        }
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $content = '';
        if ($method === 'POST') {
            $content = http_build_query($fields);
            $lines[] = 'Content-Type: application/x-www-form-urlencoded';
            $lines[] = 'Content-Length: ' . strlen($content);
        }

        return implode("\r\n", $lines) . "\r\n\r\n" . $content;
    }

    /** @return resource */
    private function connect()
    {
        $connection = @stream_socket_client('tcp://' . $this->host . ':' . $this->port, $errno, $error, 10);
        if ($connection === false) {
            throw new RuntimeException("Cannot reach {$this->host}:{$this->port}: $error");
        }
        stream_set_timeout($connection, 120);

        return $connection;
    }

    /**
     * @param resource $connection
     *
     * @return array{status: int, headers: array<string, string>, body: string, cookies: list<string>}
     */
    private function read($connection, ?int $maxBody, ?callable $sink = null): array
    {
        $head = '';
        while (!str_contains($head, "\r\n\r\n")) {
            $chunk = fread($connection, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $head .= $chunk;
        }
        $split = strpos($head, "\r\n\r\n");
        $body = $split === false ? '' : substr($head, $split + 4);
        $head = $split === false ? $head : substr($head, 0, $split);
        $received = 0;
        if ($sink !== null && $body !== '') {
            $sink($body);
            $received = strlen($body);
            $body = '';
        }
        while ($maxBody === null || ($sink === null ? strlen($body) : $received) < $maxBody) {
            $chunk = fread($connection, $maxBody === null ? 65536 : max(1, min(65536, $maxBody - ($sink === null ? strlen($body) : $received))));
            if ($chunk === false || $chunk === '') {
                if (feof($connection)) {
                    break;
                }
                $meta = stream_get_meta_data($connection);
                if ($meta['timed_out']) {
                    throw new RuntimeException('The web server did not answer in time.');
                }
                continue;
            }
            if ($sink !== null) {
                $sink($chunk);
                $received += strlen($chunk);
            } else {
                $body .= $chunk;
            }
        }
        fclose($connection);

        $lines = explode("\r\n", $head);
        $status = preg_match('~^HTTP/\S+ (\d+)~', (string) array_shift($lines), $m) === 1 ? (int) $m[1] : 0;
        $headers = [];
        $cookies = [];
        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name = strtolower($name);
            $headers[$name] = trim($value);
            if ($name === 'set-cookie') {
                $cookies[] = trim($value);
                if (preg_match('/^threadsafe_session=([^;]*)/', trim($value), $c) === 1) {
                    $this->cookie = $c[1];
                }
            }
        }
        if (preg_match('/<meta name="csrf-token" content="([0-9a-f]+)"/', $body, $m) === 1) {
            $this->csrf = $m[1];
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $body, 'cookies' => $cookies];
    }
}
