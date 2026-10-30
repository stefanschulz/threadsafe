<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use ThreadSafe\Core\Version;
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
     * @param bool   $pretty  whether routes are paths (needs the web server rule) or a query parameter
     * @param string $version the version of the program, which the addresses of the static files carry
     * @param bool   $assetsThroughPhp whether the static files are always asked for through `index.php`: an installation from a release has
     *                         them in the release, not in the document root, so no file is there for the web server to find and every
     *                         address depends on its rewrite rule - which a host may get wrong (docs/PHASE7.md, field test of 0.9.0-beta.1)
     */
    public function __construct(public string $base = '', public bool $pretty = true, public string $version = Version::CURRENT, public bool $assetsThroughPhp = false)
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

    /**
     * A static file of the interface. The version in the address makes a new version a new address (the files are cached for a year in a
     * release). Without the web server rule, or where the files are not in the document root, the file is asked for through `index.php`,
     * which answers it ({@see Assets}).
     */
    public function asset(string $name): string
    {
        $version = rawurlencode($this->version);

        return $this->pretty && !$this->assetsThroughPhp ?$this->base . '/' . $name . '?v=' . $version : $this->base . '/index.php?asset=' . $name . '&v=' . $version;
    }

    /** The path the session cookie is valid for: the application's directory. */
    public function cookiePath(): string
    {
        return $this->base === '' ? '/' : $this->base . '/';
    }
}
