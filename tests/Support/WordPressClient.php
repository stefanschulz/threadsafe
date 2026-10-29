<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

/**
 * A browser for the WordPress tests: it logs in at `wp-login.php` and keeps the cookies, which `Stack::get()` cannot (it keeps one value per
 * header name, and a login sets several cookies). Like `Stack`, it talks to a service by name and sets the Host header by hand.
 */
final class WordPressClient
{
    /** @var array<string, string> cookie name => value */
    private array $cookies = [];

    public function __construct(private readonly string $baseUrl, private readonly string $host, private readonly string $path = '')
    {
    }

    /** Logs in; true if WordPress accepted the password (it answers with a redirect and the cookie of a logged-in user). */
    public function login(string $user, string $password): bool
    {
        $this->cookies = ['wordpress_test_cookie' => 'WP%20Cookie%20check'];
        $response = $this->send('POST', $this->path . '/wp-login.php', http_build_query([
            'log' => $user, 'pwd' => $password, 'wp-submit' => 'Log In', 'redirect_to' => $this->path . '/wp-admin/', 'testcookie' => '1',
        ]));

        return $response['status'] === 302 && $this->hasLoginCookie();
    }

    private function hasLoginCookie(): bool
    {
        foreach ($this->cookies as $name => $value) {
            if (str_starts_with($name, 'wordpress_logged_in_') && $value !== '' && $value !== 'deleted') {
                return true;
            }
        }

        return false;
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    public function get(string $path): array
    {
        return $this->send('GET', $this->path . $path, null);
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function send(string $method, string $path, ?string $body): array
    {
        $cookie = [];
        foreach ($this->cookies as $name => $value) {
            $cookie[] = "$name=$value";
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => "Host: {$this->host}\r\nUser-Agent: ThreadSafe-Tests\r\n"
                . ($cookie === [] ? '' : 'Cookie: ' . implode('; ', $cookie) . "\r\n")
                . ($body === null ? '' : "Content-Type: application/x-www-form-urlencoded\r\n"),
            'content' => $body ?? '',
            'follow_location' => 0,
            'ignore_errors' => true,
            'timeout' => 60,
        ]]);
        $content = @file_get_contents($this->baseUrl . $path, false, $context);

        $status = 0;
        $headers = [];
        foreach ($http_response_header as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m) === 1) {
                $status = (int) $m[1];
                $headers = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $name = strtolower(trim($name));
                $headers[$name] = trim($value);
                if ($name === 'set-cookie') {
                    [$pair] = explode(';', trim($value), 2);
                    [$cookieName, $cookieValue] = array_pad(explode('=', $pair, 2), 2, '');
                    $this->cookies[$cookieName] = $cookieValue;
                }
            }
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $content === false ? '' : $content];
    }
}
