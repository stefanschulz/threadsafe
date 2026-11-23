<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

/**
 * A browser for the tests of the PrestaShop module: it logs in to the back office and keeps the cookies and the `_token` that PrestaShop 9 puts into every
 * address of a page. It talks to a service by name and sets the Host header by hand, like `WordPressClient`.
 */
final class PrestaShopClient
{
    /** @var array<string, string> cookie name => value */
    private array $cookies = [];

    private string $token = '';

    public function __construct(private readonly string $baseUrl, private readonly string $host, private readonly string $admin = '/admin-dev')
    {
    }

    /** Logs in; true if PrestaShop took the password and led to the dashboard. */
    public function login(string $email, string $password): bool
    {
        $this->cookies = [];
        $this->token = '';
        $this->follow('GET', $this->admin . '/index.php?controller=AdminLogin', null);
        $response = $this->follow('POST', $this->admin . '/index.php?controller=AdminLogin', http_build_query([
            'email' => $email, 'passwd' => $password, 'submitLogin' => '1', 'controller' => 'AdminLogin',
        ]));

        return $response['status'] === 200 && str_contains($response['body'], 'Dashboard') && $this->token !== '';
    }

    /** The address of the page of a module: PrestaShop's own route, with the token of this login. */
    public function configureUrl(string $module): string
    {
        return $this->admin . '/index.php/improve/modules/manage/action/configure/' . $module . '?_token=' . $this->token();
    }

    /** The token of the back office for this login: the one that PrestaShop puts into the addresses it leads the person to. */
    private function token(): string
    {
        if ($this->token === '') {
            $this->follow('GET', $this->admin . '/index.php?controller=AdminModules', null);
        }

        return $this->token;
    }

    /**
     * Installs or updates a module from a ZIP, as the module manager of the back office does (the route of 9.0.2: `improve/modules/import`).
     *
     * @return array<string, mixed> the answer of PrestaShop (`status`, `msg`, `module_name` ...)
     */
    public function importModule(string $zipPath): array
    {
        $boundary = '----tsboundary' . bin2hex(random_bytes(6));
        $body = "--$boundary\r\nContent-Disposition: form-data; name=\"file_uploaded\"; filename=\"" . basename($zipPath) . "\"\r\n"
            . "Content-Type: application/zip\r\n\r\n" . (string) file_get_contents($zipPath) . "\r\n--$boundary--\r\n";
        $response = $this->request('POST', $this->admin . '/index.php/improve/modules/import?_token=' . $this->token(), $body, ['Content-Type: multipart/form-data; boundary=' . $boundary]);
        $data = json_decode($response['body'], true);

        return is_array($data) ? $data : ['status' => false, 'msg' => 'HTTP ' . $response['status'] . ': ' . substr(strip_tags($response['body']), 0, 300)];
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    public function get(string $path): array
    {
        return $this->follow('GET', $path, null);
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function post(string $path, array $fields): array
    {
        return $this->follow('POST', $path, http_build_query($fields));
    }

    /**
     * Follows redirects as a browser does: 301 to 303 turn into a GET, 307 and 308 repeat the request. The `_token` of every address that PrestaShop leads to
     * is remembered.
     *
     * @phpstan-impure
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function follow(string $method, string $path, ?string $body): array
    {
        $response = $this->request($method, $path, $body, []);
        for ($i = 0; $i < 8 && in_array($response['status'], [301, 302, 303, 307, 308], true) && isset($response['headers']['location']); ++$i) {
            $location = $response['headers']['location'];
            if (preg_match('~[?&]_token=([^&"\']+)~', $location, $m) === 1) {
                $this->token = $m[1];
            }
            if (in_array($response['status'], [307, 308], true)) {
                $response = $this->request($method, self::relative($location), $body, []);
            } else {
                $method = 'GET';
                $body = null;
                $response = $this->request('GET', self::relative($location), null, []);
            }
        }

        return $response;
    }
    private static function relative(string $location): string
    {
        $parts = parse_url($location);

        return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * @param list<string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function request(string $method, string $path, ?string $body, array $headers): array
    {
        $cookie = [];
        foreach ($this->cookies as $name => $value) {
            $cookie[] = "$name=$value";
        }
        if ($body !== null && $headers === []) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => "Host: {$this->host}\r\nUser-Agent: ThreadSafe-Tests\r\n" . ($cookie === [] ? '' : 'Cookie: ' . implode('; ', $cookie) . "\r\n") . implode("\r\n", $headers),
            'content' => $body ?? '',
            'follow_location' => 0,
            'ignore_errors' => true,
            'timeout' => 120,
        ]]);
        $content = @file_get_contents($this->baseUrl . $path, false, $context);

        $status = 0;
        $received = [];
        foreach ($http_response_header as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m) === 1) {
                $status = (int) $m[1];
                $received = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $name = strtolower(trim($name));
                $received[$name] = trim($value);
                if ($name === 'set-cookie') {
                    [$pair] = explode(';', trim($value), 2);
                    [$cookieName, $cookieValue] = array_pad(explode('=', $pair, 2), 2, '');
                    $this->cookies[$cookieName] = $cookieValue;
                }
            }
        }

        return ['status' => $status, 'headers' => $received, 'body' => $content === false ? '' : $content];
    }
}
