<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Admin\Context;
use ThreadSafe\Admin\Csrf;
use ThreadSafe\Admin\MethodNotAllowed;
use ThreadSafe\Admin\Router;
use ThreadSafe\Admin\Urls;
use ThreadSafe\Core\Version;
use ThreadSafe\Web\Request;
use ThreadSafe\Web\Response;

/**
 * Addresses and routes: what part of a request is the route, which patterns match, and the two checks against forged requests.
 */
final class RoutingTest extends TestCase
{
    private static function handler(): callable
    {
        return static fn (Context $context, array $params): Response => Response::html('x');
    }

    public function testTheRouteIsThePathBelowTheDirectoryOfIndexPhp(): void
    {
        $urls = new Urls('/threadsafe/public');

        self::assertSame('/', $urls->route(new Request(path: '/threadsafe/public/')));
        self::assertSame('/', $urls->route(new Request(path: '/threadsafe/public')));
        self::assertSame('/', $urls->route(new Request(path: '/threadsafe/public/index.php')));
        self::assertSame('/profiles/shop', $urls->route(new Request(path: '/threadsafe/public/profiles/shop')));
        self::assertSame('/profiles/shop', $urls->route(new Request(path: '/threadsafe/public/index.php/profiles/shop/')));
        self::assertSame('/login', (new Urls(''))->route(new Request(path: '/login')));
    }

    public function testWithoutPrettyUrlsTheRouteIsAParameter(): void
    {
        $urls = new Urls('/ts', pretty: false);

        self::assertSame('/profiles/shop', $urls->route(new Request(query: ['route' => '/profiles/shop'], path: '/ts/index.php')));
        self::assertSame('/', $urls->route(new Request(path: '/ts/index.php')));
        self::assertSame('/ts/index.php?route=/profiles/shop', $urls->to('/profiles/shop'));
        self::assertSame('/ts/index.php', $urls->to('/'));
        self::assertSame('/ts/index.php?route=/backups&a=1%202', $urls->to('/backups', ['a' => '1 2']));
    }

    public function testPrettyAddressesAndAssetsAndTheCookiePath(): void
    {
        $urls = new Urls('/ts');

        self::assertSame('/ts/', $urls->to('/'));
        self::assertSame('/ts/profiles/shop?tab=files', $urls->to('/profiles/shop', ['tab' => 'files']));
        self::assertSame('/ts/app.css?v=' . Version::CURRENT, $urls->asset('app.css'));
        self::assertSame('/ts/app.css?v=1.2.3', (new Urls('/ts', true, '1.2.3'))->asset('app.css'));
        self::assertSame('/ts/index.php?asset=fonts/a.woff2&v=1.2.3', (new Urls('/ts', false, '1.2.3'))->asset('fonts/a.woff2'), 'without the rule the file is asked for through index.php');
        self::assertSame('/ts/index.php?asset=app.css&v=1.2.3', (new Urls('/ts', true, '1.2.3', assetsThroughPhp: true))->asset('app.css'), 'where the files are not in the document root, no rewrite is needed for them');
        self::assertSame('/ts/', $urls->cookiePath());
        self::assertSame('/', (new Urls(''))->cookiePath());
    }

    public function testAPatternMatchesOnlyWhatItsPlaceholdersAllow(): void
    {
        $router = new Router();
        $router->add('GET', '/profiles/{id}/backups/{name}', self::handler());

        $match = $router->match('GET', '/profiles/shop-1/backups/shop-1-20261021T120000Z.zip');
        self::assertNotNull($match);
        self::assertSame(['id' => 'shop-1', 'name' => 'shop-1-20261021T120000Z.zip'], $match['params']);

        foreach (['/profiles/Shop/backups/a.zip', '/profiles/../backups/a.zip', '/profiles/shop/backups/..', '/profiles/shop/backups/a/b', '/profiles/shop/backups/', '/profiles//backups/a', '/profiles/shop/backups/.hidden'] as $route) {
            self::assertNull($router->match('GET', $route), $route);
        }
    }

    public function testAnotherMethodOnAKnownPathIsNotAllowedRatherThanNotFound(): void
    {
        $router = new Router();
        $router->add('POST', '/logout', self::handler());
        $router->add('GET', '/logout-help', self::handler());

        try {
            $router->match('GET', '/logout');
            self::fail('a GET must not log out');
        } catch (MethodNotAllowed $e) {
            self::assertSame(['POST'], $e->allowed);
        }
        self::assertNull($router->match('GET', '/nothing'));
    }

    public function testPublicRoutesAreMarked(): void
    {
        $router = new Router();
        $router->add('GET', '/login', self::handler(), public: true);
        $router->add('GET', '/', self::handler());

        self::assertTrue($router->match('GET', '/login')['public'] ?? false);
        self::assertFalse($router->match('GET', '/')['public'] ?? true);
    }

    public function testTheTokenCanComeInTheFormOrInTheHeader(): void
    {
        self::assertTrue(Csrf::valid(new Request('POST', post: ['csrf' => 'abc123']), 'abc123'));
        self::assertTrue(Csrf::valid(new Request('POST', headers: ['x-csrf-token' => 'abc123']), 'abc123'));
        self::assertFalse(Csrf::valid(new Request('POST', post: ['csrf' => 'abc124']), 'abc123'));
        self::assertFalse(Csrf::valid(new Request('POST'), 'abc123'));
        self::assertFalse(Csrf::valid(new Request('POST', post: ['csrf' => '']), ''), 'no session token never matches');
    }

    public function testAPostMustComeFromThisSiteWhenTheBrowserSaysWhere(): void
    {
        $host = ['host' => 'backup.example.com'];

        self::assertTrue(Csrf::sameOrigin(new Request('POST', headers: $host + ['origin' => 'https://backup.example.com'])));
        self::assertTrue(Csrf::sameOrigin(new Request('POST', headers: $host + ['referer' => 'https://backup.example.com/profiles/shop'])));
        self::assertTrue(Csrf::sameOrigin(new Request('POST', headers: ['host' => 'localhost:8290', 'origin' => 'http://localhost:8290'])));
        self::assertTrue(Csrf::sameOrigin(new Request('POST', headers: $host)), 'no origin header: the cookie and the token still have to match');
        self::assertFalse(Csrf::sameOrigin(new Request('POST', headers: $host + ['origin' => 'https://evil.example'])));
        self::assertFalse(Csrf::sameOrigin(new Request('POST', headers: $host + ['origin' => 'https://backup.example.com.evil.example'])));
        self::assertFalse(Csrf::sameOrigin(new Request('POST', headers: $host + ['origin' => 'null'])));
        self::assertFalse(Csrf::sameOrigin(new Request('POST', headers: $host + ['origin' => 'not a url'])));
        self::assertFalse(Csrf::sameOrigin(new Request('POST', headers: ['host' => 'localhost:8290', 'origin' => 'http://localhost:9999'])));
    }

    public function testTheRequestCollectsItsHeadersFromTheServerVariables(): void
    {
        $saved = $_SERVER;
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/a/b?x=1', 'HTTP_HOST' => 'h.example', 'HTTP_X_CSRF_TOKEN' => 'tok', 'HTTP_ORIGIN' => 'https://h.example', 'CONTENT_TYPE' => 'x'];
        try {
            $request = Request::fromGlobals();
        } finally {
            $_SERVER = $saved;
        }

        self::assertSame('tok', $request->header('x-csrf-token'));
        self::assertSame('h.example', $request->header('host'));
        self::assertNull($request->header('content-type'), 'only HTTP_ variables become headers');
        self::assertSame('/a/b', $request->path);
    }
}
