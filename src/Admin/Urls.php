<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use ThreadSafe\Web\Request;

/**
 * The addresses of the pages. The web server is set to hand every address below the directory of `index.php` to `index.php`
 * (`public/.htaccess`; for nginx see docs/WEB.md), and the part after that directory is the route (`/profiles/shop`). A host that
 * cannot do that sets `web.prettyUrls` to false, and the pages are then `index.php?route=/profiles/shop`.
 */
final readonly class Urls
{
    /**
     * @param string $base   the path of the directory `index.php` is in, without a trailing slash (`/threadsafe/public`, or empty)
     * @param bool   $pretty whether routes are paths (needs the web server rule) or a query parameter
     */
    public function __construct(public string $base = '', public bool $pretty = true)
    {
    }

    /**
     * The route of a request: the path below the base, or the `route` parameter.
     */
    public function route(Request $request): string
    {
        $route = $request->query('route');
        if ($route === null) {
            $path = $request->path;
            $route = str_starts_with($path, $this->base) ? substr($path, strlen($this->base)) : $path;
            if ($route === '/index.php' || str_starts_with($route, '/index.php/')) {
                $route = substr($route, strlen('/index.php'));
            }
        }
        $route = '/' . trim($route, '/');

        return $route;
    }

    /**
     * @param array<string, string> $query
     */
    public function to(string $route, array $query = []): string
    {
        if ($this->pretty) {
            $url = $this->base . ($route === '/' ? '/' : $route);
        } else {
            $url = $this->base . '/index.php' . ($route === '/' ? '' : '?route=' . str_replace('%2F', '/', rawurlencode($route)));
        }
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    /** A static file next to `index.php`. */
    public function asset(string $name): string
    {
        return $this->base . '/' . $name;
    }

    /** The path the session cookie is valid for: the application's directory. */
    public function cookiePath(): string
    {
        return $this->base === '' ? '/' : $this->base . '/';
    }
}
