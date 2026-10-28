<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use ThreadSafe\Web\Request;
use ThreadSafe\Web\Response;
use ThreadSafe\Web\Wizard;

/**
 * A browser for the wizard, without a web server: keeps the cookie, finds the CSRF token in the last page, follows redirects.
 */
final class WizardClient
{
    private ?string $cookie = null;

    private string $csrf = '';

    /**
     * @param string $host the Host header the browser sends (empty: none)
     */
    public function __construct(private readonly Wizard $wizard, private readonly string $address = '127.0.0.1', private readonly bool $https = false, private readonly string $host = '')
    {
    }

    /**
     * @param array<string, string> $query
     */
    public function get(array $query = [], bool $follow = true): Response
    {
        return $this->send(new Request('GET', $query, [], $this->cookies(), $this->https, $this->address, '/restore.php', $this->headers()), $follow);
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

        return $this->send(new Request('POST', $query, $fields, $this->cookies(), $this->https, $this->address, '/restore.php', $this->headers()), $follow);
    }

    public function csrf(): string
    {
        return $this->csrf;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->host === '' ? [] : ['host' => $this->host];
    }

    /** @return array<string, string> */
    private function cookies(): array
    {
        return $this->cookie === null ? [] : [Wizard::COOKIE => $this->cookie];
    }

    private function send(Request $request, bool $follow): Response
    {
        for ($hops = 0; $hops < 10; ++$hops) {
            $response = $this->wizard->handle($request);
            foreach ($response->headers('Set-Cookie') as $line) {
                if (preg_match('/^' . Wizard::COOKIE . '=([0-9a-f]+);/', $line, $m) === 1) {
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
            $request = new Request('GET', $query, [], $this->cookies(), $this->https, $this->address, '/restore.php', $this->headers());
        }

        throw new \RuntimeException('Too many redirects.');
    }
}
