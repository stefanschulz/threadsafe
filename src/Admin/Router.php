<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

/**
 * Maps a method and a route to a handler. A pattern is a path in which `{id}` stands for a profile id (a slug), `{name}` for a
 * backup or part name, `{hex}` for a token; nothing else is accepted in those places, so a handler never sees `..` or a slash
 * from the request, and an address that does not fit is simply not found.
 */
final class Router
{
    private const array PLACEHOLDERS = [
        'id' => '[a-z0-9][a-z0-9_-]{0,39}',
        'name' => '[A-Za-z0-9][A-Za-z0-9._-]{0,127}',
        'hex' => '[0-9a-f]{16,128}',
    ];

    /** @var list<array{method: string, regex: string, handler: callable(Context, array<string, string>): \ThreadSafe\Web\Response, public: bool}> */
    private array $routes = [];

    /**
     * @param callable(Context, array<string, string>): \ThreadSafe\Web\Response $handler
     * @param bool                                                                $public  reachable without a login
     */
    public function add(string $method, string $pattern, callable $handler, bool $public = false): void
    {
        $regex = preg_replace_callback('/\{(\w+)\}/', static function (array $m): string {
            $expression = self::PLACEHOLDERS[$m[1]] ?? throw new \LogicException("Unknown placeholder {{$m[1]}} in the route \"$m[0]\".");

            return '(?P<' . $m[1] . '>' . $expression . ')';
        }, $pattern);
        $this->routes[] = ['method' => strtoupper($method), 'regex' => '#^' . $regex . '$#', 'handler' => $handler, 'public' => $public];
    }

    /**
     * @return array{handler: callable(Context, array<string, string>): \ThreadSafe\Web\Response, params: array<string, string>, public: bool}|null the match,
     *                                                                                                                   null if no route has this path
     *
     * @throws MethodNotAllowed the path exists, but not for this method
     */
    public function match(string $method, string $route): ?array
    {
        $allowed = [];
        foreach ($this->routes as $candidate) {
            if (preg_match($candidate['regex'], $route, $m) !== 1) {
                continue;
            }
            if ($candidate['method'] !== strtoupper($method)) {
                $allowed[] = $candidate['method'];
                continue;
            }
            $params = [];
            foreach ($m as $name => $value) {
                if (is_string($name)) {
                    $params[$name] = $value;
                }
            }

            return ['handler' => $candidate['handler'], 'params' => $params, 'public' => $candidate['public']];
        }
        if ($allowed !== []) {
            throw new MethodNotAllowed(array_values(array_unique($allowed)));
        }

        return null;
    }
}
