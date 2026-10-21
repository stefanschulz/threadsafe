<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use RuntimeException;

/**
 * A wizard behind PHP's built-in web server on a free port, and a browser for it: real requests, a real cookie, real headers.
 */
final class WebWizard
{
    /** @var resource|null */
    private $server;

    private int $port = 0;

    private ?string $cookie = null;

    private string $csrf = '';

    /**
     * @param string                $router   the script the server runs for every request (restore.php, or tools/restore-web.php)
     * @param array<string, string> $settings php.ini settings for the server, e.g. ['max_execution_time' => '5']
     * @param array<string, string> $env      environment of the server process
     * @param string|null           $baseUrl  a web server that is running already (the target stack's Apache, `http://target-prestashop`); then
     *                                        nothing is started and the tool is at $scriptPath
     * @param string                $scriptPath the path of the tool on that server, e.g. `/restore.php`
     */
    public function __construct(private readonly string $router = '', private readonly array $settings = [], private readonly array $env = [], private readonly ?string $baseUrl = null, private readonly string $scriptPath = '')
    {
    }

    public function start(): void
    {
        if ($this->baseUrl !== null) {
            return;
        }
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($probe === false) {
            throw new RuntimeException("No free port: $error");
        }
        $name = (string) stream_socket_get_name($probe, false);
        $this->port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($probe);

        $command = [PHP_BINARY];
        foreach ($this->settings as $key => $value) {
            array_push($command, '-d', "$key=$value");
        }
        array_push($command, '-S', '127.0.0.1:' . $this->port, $this->router);
        $server = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, [...$this->env, 'PATH' => (string) getenv('PATH')]);
        if (!is_resource($server)) {
            throw new RuntimeException('Cannot start the PHP web server.');
        }
        $this->server = $server;
        for ($i = 0; $i < 100; ++$i) {
            $connection = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.2);
            if ($connection !== false) {
                fclose($connection);

                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('The PHP web server did not start.');
    }

    public function stop(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $this->server = null;
    }

    public function csrf(): string
    {
        return $this->csrf;
    }

    public function hasCookie(): bool
    {
        return $this->cookie !== null;
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function request(string $method, string $path, array $fields = []): array
    {
        $headers = ['Connection: close'];
        if ($this->cookie !== null) {
            $headers[] = 'Cookie: threadsafe_restore=' . $this->cookie;
        }
        $content = '';
        if ($method === 'POST') {
            $content = http_build_query($fields);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $content, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 120]]);
        if ($this->scriptPath !== '') {
            $path = $path === '/' ? $this->scriptPath : $this->scriptPath . substr($path, 1);
        }
        $body = @file_get_contents(($this->baseUrl ?? 'http://127.0.0.1:' . $this->port) . $path, false, $context);
        $lines = $http_response_header;
        if ($body === false) {
            throw new RuntimeException('The web server gave no answer.');
        }

        $status = 0;
        $parsed = [];
        foreach ($lines as $line) {
            if (preg_match('~^HTTP/\S+ (\d+)~', $line, $m) === 1) {
                $status = (int) $m[1];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $parsed[strtolower($name)] = trim($value);
            }
        }
        if (isset($parsed['set-cookie']) && preg_match('/threadsafe_restore=([0-9a-f]+)/', $parsed['set-cookie'], $m) === 1) {
            $this->cookie = $m[1];
        }
        if (preg_match('/name="csrf" value="([0-9a-f]+)"/', $body, $m) === 1) {
            $this->csrf = $m[1];
        }

        return ['status' => $status, 'headers' => $parsed, 'body' => $body];
    }

    /**
     * @param array{status: int, headers: array<string, string>, body: string} $response
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function follow(array $response): array
    {
        for ($i = 0; $i < 5 && $response['status'] === 303; ++$i) {
            $response = $this->request('GET', '/' . $response['headers']['location']);
        }

        return $response;
    }
}
