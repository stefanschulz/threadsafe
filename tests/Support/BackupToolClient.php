<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use RuntimeException;
use ThreadSafe\Web\BackupWizard;

/**
 * A browser for the backup tool behind a real web server: real requests, the real cookie, real headers; the downloads are made by `curl`, with
 * the `Range` header of a browser that resumes.
 */
final class BackupToolClient
{
    private ?string $cookie = null;

    private string $csrf = '';

    /**
     * @param string $baseUrl the web server, e.g. `http://target-prestashop`
     * @param string $path    the path of the tool on it, e.g. `/threadsafe-backup.php`
     */
    public function __construct(private readonly string $baseUrl, private readonly string $path = '/threadsafe-backup.php')
    {
    }

    public function csrf(): string
    {
        return $this->csrf;
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function request(string $method, string $query = '', array $fields = []): array
    {
        $headers = ['Connection: close'];
        if ($this->cookie !== null) {
            $headers[] = 'Cookie: ' . BackupWizard::COOKIE . '=' . $this->cookie;
        }
        $content = '';
        if ($method === 'POST') {
            $content = http_build_query($fields + ($this->csrf !== '' ? ['csrf' => $this->csrf] : []));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $content, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 120]]);

        return $this->read($this->baseUrl . $this->path . $query, $context);
    }

    /**
     * A request that follows the redirects of the wizard.
     *
     * @param array<string, string> $fields
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function go(string $method, string $query = '', array $fields = []): array
    {
        $response = $this->request($method, $query, $fields);
        for ($i = 0; $i < 5 && $response['status'] === 303; ++$i) {
            $response = $this->request('GET', $response['headers']['location']);
        }

        return $response;
    }

    /**
     * Any address on the same server, without the cookie of the tool: what a stranger gets.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function stranger(string $relative): array
    {
        $context = stream_context_create(['http' => ['method' => 'GET', 'header' => 'Connection: close', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 60]]);

        return $this->read($this->baseUrl . '/' . ltrim($relative, '/'), $context);
    }

    /**
     * Fetches a part of the finished backup with `curl`, as a browser does, optionally only a range of it.
     *
     * @return array{int, string} the status and the headers of the answer
     */
    public function download(string $part, string $file, ?string $range = null): array
    {
        $command = ['curl', '-s', '-o', $file, '-D', $file . '.headers', '-w', '%{http_code}', '-b', BackupWizard::COOKIE . '=' . $this->cookie];
        if ($range !== null) {
            array_push($command, '-H', 'Range: bytes=' . $range);
        }
        $command[] = $this->baseUrl . $this->path . '?step=file&part=' . rawurlencode($part);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start curl.');
        }
        $status = (int) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($process);

        return [$status, (string) @file_get_contents($file . '.headers')];
    }

    /**
     * @param resource $context
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function read(string $url, $context): array
    {
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException("The web server gave no answer for $url.");
        }
        $lines = $http_response_header;

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
        if (isset($parsed['set-cookie']) && preg_match('/' . BackupWizard::COOKIE . '=([0-9a-f]+)/', $parsed['set-cookie'], $m) === 1) {
            $this->cookie = $m[1];
        }
        if (preg_match('/name="csrf" value="([0-9a-f]+)"/', $body, $m) === 1) {
            $this->csrf = $m[1];
        }

        return ['status' => $status, 'headers' => $parsed, 'body' => $body];
    }
}
