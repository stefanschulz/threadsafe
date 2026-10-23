<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use ThreadSafe\Admin\AdminStore;
use ThreadSafe\Admin\Config;
use ThreadSafe\Admin\Kernel;
use ThreadSafe\Web\Request;
use ThreadSafe\Web\Response;

/**
 * A browser for the tests of the web interface that needs no web server: it keeps the session cookie and the CSRF token, sends
 * requests to the {@see Kernel} and hands back the responses. The installation behind it is made with {@see install()}.
 */
final class AdminBrowser
{
    public const string PASSWORD = 'correct horse battery';

    public int $now = 1_800_000_000;

    public string $cookie = '';

    /** @var array<string, string> more cookies of the browser, such as the choices of the menu */
    public array $cookies = [];

    public string $csrf = '';

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
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     */
    public function send(string $method, string $route, array $fields = [], array $headers = [], string $address = '127.0.0.1'): Response
    {
        $request = new Request($method, [], $fields, ($this->cookie === '' ? [] : ['threadsafe_session' => $this->cookie]) + $this->cookies, false, $address, $this->base . $route, $headers + ['host' => 'ts.example']);
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
