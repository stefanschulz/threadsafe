<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use ThreadSafe\Web\Request;
use ThreadSafe\Web\Response;

/**
 * The static files of the interface (`app.css`, `app.js`, the fonts), answered by PHP when the web server does not: an installation
 * from a release has them in the release's `public/`, which is not the document root (docs/PHASE7.md 3.2), and a host without
 * `mod_rewrite` asks for them as `index.php?asset=app.css`. The pages ask for them with `?v=<version>`, so a new version is never
 * served from an old cache; in a release the files are then cacheable for a year.
 *
 * Only a whitelist of names is answered, and only from the one directory.
 */
final readonly class Assets
{
    private const string NAMES = '#^(app\.css|app\.js|favicon\.svg|fonts/[A-Za-z0-9][A-Za-z0-9._-]*)$#';

    private const array TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'svg' => 'image/svg+xml',
        'woff2' => 'font/woff2',
        'txt' => 'text/plain; charset=utf-8',
    ];

    /**
     * @param string $directory the `public/` directory of the program
     * @param bool   $immutable whether a file may be cached for a year (a release: its name carries the version); a clone changes files in place
     */
    public function __construct(private string $directory, private string $version, private bool $immutable)
    {
    }

    /**
     * The name of the file the request asks for, or null if it asks for something else.
     *
     * @param string $base the path of the directory `index.php` is in, without a trailing slash
     */
    public function name(Request $request, string $base): ?string
    {
        if ($request->method !== 'GET' && $request->method !== 'HEAD') {
            return null;
        }
        $name = $request->query('asset');
        if ($name === null) {
            $prefix = $base . '/';
            $name = str_starts_with($request->path, $prefix) ? rawurldecode(substr($request->path, strlen($prefix))) : null;
        }

        return $name !== null && preg_match(self::NAMES, $name) === 1 ? $name : null;
    }

    /**
     * The answer, or null if the request is not for a file of the whitelist (then the interface answers it). A name of the whitelist that
     * is not there is a 404.
     */
    public function answer(Request $request, string $base): ?Response
    {
        $name = $this->name($request, $base);
        if ($name === null) {
            return null;
        }
        $root = realpath($this->directory);
        $path = $root === false ? false : realpath($root . '/' . $name);
        if ($root === false || $path === false || !is_file($path) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            return new Response(404, ['Content-Type: text/plain; charset=utf-8', 'X-Content-Type-Options: nosniff'], "Not found.\n");
        }
        $size = (int) filesize($path);
        $etag = '"' . substr(sha1($this->version . ':' . $size . ':' . (int) filemtime($path)), 0, 20) . '"';
        $headers = [
            'Content-Type: ' . (self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream'),
            'X-Content-Type-Options: nosniff',
            'ETag: ' . $etag,
            'Cache-Control: ' . ($this->immutable && $request->query('v') === $this->version ? 'public, max-age=31536000, immutable' : 'no-cache'),
        ];
        if ($request->header('if-none-match') === $etag) {
            return new Response(304, $headers, '');
        }

        $body = (string) file_get_contents($path);
        if ($name === 'app.css' && $request->query('asset') !== null) {
            $body = $this->fontsThroughPhp($body);
        }

        return new Response(200, $headers, $body);
    }

    /**
     * The style sheet names its fonts relative to itself (`fonts/x.woff2`), which is right where the web server serves the files. When the
     * sheet itself was asked for through `index.php`, those addresses would lead to the document root, where nothing is: they go through
     * `index.php` as well (a relative address with only a query stays on the same script, also in a sub-directory).
     */
    private function fontsThroughPhp(string $css): string
    {
        return (string) preg_replace_callback(
            '#url\("fonts/([A-Za-z0-9][A-Za-z0-9._-]*)"\)#',
            fn (array $m): string => 'url("index.php?asset=fonts/' . $m[1] . '&v=' . rawurlencode($this->version) . '")',
            $css,
        );
    }
}
