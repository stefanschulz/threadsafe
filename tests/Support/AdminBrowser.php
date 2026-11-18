<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use ThreadSafe\Admin\AdminStore;
use ThreadSafe\Admin\Config;
use ThreadSafe\Admin\Kernel;
use ThreadSafe\Auth\RecoveryCodes;
use ThreadSafe\Auth\Totp;
use ThreadSafe\Auth\TwoFactorStore;
use ThreadSafe\Web\Request;
use ThreadSafe\Web\Response;

/**
 * A browser for the tests of the web interface that needs no web server: it keeps the session cookie and the CSRF token, sends
 * requests to the {@see Kernel} and hands back the responses. The installation behind it is made with {@see install()}.
 */
final class AdminBrowser
{
    public const string PASSWORD = 'correct horse battery';

    /** The secret of the second factor that {@see enableTwoFactor()} sets (the key of the examples in RFC 6238). */
    public const string SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public int $now = 1_800_000_000;

    public string $cookie = '';

    /** @var array<string, string> more cookies of the browser, such as the choices of the menu */
    public array $cookies = [];

    public string $csrf = '';

    /** The host name that the browser asked for, and whether it used https (passkeys need a secure page and a name). */
    public string $host = 'ts.example';

    public bool $https = false;

    /**
     * @param (\Closure(): \ThreadSafe\Engine\Budget)|null $budgets the budget of a tick (default: the share of the request time limit, which is none in a test)
     */
    public function __construct(public readonly string $data, private readonly string $base = '', private readonly ?\Closure $budgets = null)
    {
    }

    /** An installation that is switched on and has an administrator. */
    public function install(): void
    {
        if (!is_dir($this->data)) {
            mkdir($this->data, 0700, true);
        }
        (new AdminStore($this->data . '/admin.json'))->setPassword('admin', self::PASSWORD);
        Config::defaults()->with('web.enabled', true)->save($this->data . '/config.json');
    }

    /**
     * Switches the second factor of the administrator on, with {@see SECRET}.
     *
     * @return list<string> the recovery codes
     */
    public function enableTwoFactor(): array
    {
        $codes = RecoveryCodes::generate();
        (new TwoFactorStore($this->data . '/two-factor.json', fn (): int => $this->now))->enable('admin', self::SECRET, $codes);

        return $codes;
    }

    /** The code that the app shows now (or $steps steps later or earlier). */
    public function code(int $steps = 0): string
    {
        return Totp::code(self::SECRET, Totp::step($this->now) + $steps);
    }

    public function login(): Response
    {
        $this->get('/login');

        return $this->send('POST', '/login', ['user' => 'admin', 'password' => self::PASSWORD]);
    }

    public function get(string $route): Response
    {
        return $this->send('GET', $route);
    }

    /**
     * A POST with the CSRF token of the session.
     *
     * @param array<string, string> $fields
     */
    public function post(string $route, array $fields = []): Response
    {
        return $this->send('POST', $route, $fields + ['csrf' => $this->csrf]);
    }

    /**
     * A POST of a script: JSON in the body and the token of the session in the header, as the page sends it.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function postJson(string $route, array $data, array $headers = ['x-csrf-token' => '']): Response
    {
        if (($headers['x-csrf-token'] ?? null) === '') {
            $headers['x-csrf-token'] = $this->csrf;
        }

        return $this->send('POST', $route, [], $headers + ['content-type' => 'application/json'], '127.0.0.1', json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     */
    public function send(string $method, string $route, array $fields = [], array $headers = [], string $address = '127.0.0.1', string $body = ''): Response
    {
        // a route may carry a query string (`/?type=generic`): it goes into the query of the request, not into the path
        [$route, $queryString] = explode('?', $route, 2) + [1 => ''];
        parse_str($queryString, $parsed);
        $query = [];
        foreach ($parsed as $name => $value) {
            if (is_string($value)) {
                $query[(string) $name] = $value;
            }
        }
        $request = new Request($method, $query, $fields, ($this->cookie === '' ? [] : ['threadsafe_session' => $this->cookie]) + $this->cookies, $this->https, $address, $this->base . $route, $headers + ['host' => $this->host], [], '', $body);
        $response = (new Kernel($this->data, $this->base, fn (): int => $this->now, $this->budgets))->handle($request);

        foreach ($response->headers('Set-Cookie') as $line) {
            if (preg_match('/^threadsafe_session=([^;]*)/', $line, $m) === 1) {
                $this->cookie = $m[1];
            }
        }
        if (preg_match('/<meta name="csrf-token" content="([0-9a-f]+)"/', $response->body, $m) === 1) {
            $this->csrf = $m[1];
        }

        return $response;
    }
}
