<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use Closure;
use ThreadSafe\Web\Request;
use ThreadSafe\Web\Response;

/**
 * A browser for a single-file tool's wizard, without a web server: keeps the cookie, finds the CSRF token in the last page, follows redirects.
 */
final class ToolClient
{
    private ?string $cookie = null;

    private string $csrf = '';

    /**
     * @param Closure(Request): Response $handle the wizard's `handle`
     * @param string                     $cookieName the name of its session cookie
     */
    public function __construct(private readonly Closure $handle, private readonly string $cookieName, private readonly string $path = '/tools/threadsafe-backup.php', private readonly string $address = '127.0.0.1', private readonly bool $https = false)
    {
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers request headers, names in lower case
     */
    public function get(array $query = [], bool $follow = true, array $headers = []): Response
    {
        return $this->send(new Request('GET', $query, [], $this->cookies(), $this->https, $this->address, $this->path, $headers), $follow);
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $query
     */
    public function post(array $fields, array $query = [], bool $follow = true, bool $withCsrf = true): Response
    {
        if ($withCsrf) {
            $fields += ['csrf' => $this->csrf];
        }

        return $this->send(new Request('POST', $query, $fields, $this->cookies(), $this->https, $this->address, $this->path, []), $follow);
    }

    public function csrf(): string
    {
        return $this->csrf;
    }

    /** @return array<string, string> */
    private function cookies(): array
    {
        return $this->cookie === null ? [] : [$this->cookieName => $this->cookie];
    }

    private function send(Request $request, bool $follow): Response
    {
        for ($hops = 0; $hops < 10; ++$hops) {
            $response = ($this->handle)($request);
            foreach ($response->headers('Set-Cookie') as $line) {
                if (preg_match('/^' . preg_quote($this->cookieName, '/') . '=([0-9a-f]+);/', $line, $m) === 1) {
                    $this->cookie = $m[1];
                }
            }
            if (preg_match('/name="csrf" value="([0-9a-f]+)"/', $response->body, $m) === 1) {
                $this->csrf = $m[1];
            }
            $location = $response->header('Location');
            if (!$follow || $response->status !== 303 || $location === null) {
                return $response;
            }
            parse_str((string) parse_url($location, PHP_URL_QUERY), $parsed);
            $query = [];
            foreach ($parsed as $name => $value) {
                $query[(string) $name] = is_string($value) ? $value : '';
            }
            $request = new Request('GET', $query, [], $this->cookies(), $this->https, $this->address, $this->path, []);
        }

        throw new \RuntimeException('Too many redirects.');
    }
}
