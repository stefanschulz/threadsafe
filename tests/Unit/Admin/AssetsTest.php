<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Admin\Assets;
use ThreadSafe\Web\Request;

/**
 * The static files that PHP answers when the web server does not: only a whitelist, only from one directory, with the caching that
 * a release allows and a clone does not.
 */
final class AssetsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/threadsafe-assets-' . bin2hex(random_bytes(4));
        mkdir($this->directory . '/fonts', 0777, true);
        file_put_contents($this->directory . '/app.css', 'body { color: #000; }');
        file_put_contents($this->directory . '/app.js', 'console.log(1);');
        file_put_contents($this->directory . '/fonts/plex.woff2', 'wOF2....');
        file_put_contents($this->directory . '/fonts/OFL.txt', 'license');
        file_put_contents($this->directory . '/index.php', '<?php echo "must never be served";');
        file_put_contents($this->directory . '/.htaccess', 'secret rules');
        file_put_contents(dirname($this->directory) . '/threadsafe-assets-outside.txt', 'outside');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            is_file($file) && unlink($file);
        }
        foreach (glob($this->directory . '/fonts/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->directory . '/fonts');
        @rmdir($this->directory);
        @unlink(dirname($this->directory) . '/threadsafe-assets-outside.txt');
    }

    private function assets(bool $immutable = true): Assets
    {
        return new Assets($this->directory, '1.2.3', $immutable);
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    private function request(string $path, array $query = [], string $method = 'GET', array $headers = []): Request
    {
        return new Request($method, $query, [], [], false, '', $path, $headers);
    }

    public function testAFileIsAnsweredWithItsTypeAndAYearOfCacheForTheVersionInTheAddress(): void
    {
        $response = $this->assets()->answer($this->request('/ts/app.css', ['v' => '1.2.3']), '/ts');

        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertSame('body { color: #000; }', $response->body);
        self::assertSame('text/css; charset=utf-8', $response->header('Content-Type'));
        self::assertSame('public, max-age=31536000, immutable', $response->header('Cache-Control'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
    }

    public function testWithoutTheVersionOrInAClonetheFileIsRevalidatedEveryTime(): void
    {
        $noVersion = $this->assets()->answer($this->request('/app.css'), '');
        $otherVersion = $this->assets()->answer($this->request('/app.css', ['v' => '0.9.0']), '');
        $clone = $this->assets(immutable: false)->answer($this->request('/app.css', ['v' => '1.2.3']), '');

        foreach ([$noVersion, $otherVersion, $clone] as $response) {
            self::assertNotNull($response);
            self::assertSame('no-cache', $response->header('Cache-Control'));
        }
    }

    public function testAFileThatTheBrowserHasIsNotSentAgain(): void
    {
        $first = $this->assets()->answer($this->request('/app.js'), '');
        self::assertNotNull($first);
        $etag = $first->header('ETag');
        self::assertNotNull($etag);

        $again = $this->assets()->answer($this->request('/app.js', [], 'GET', ['if-none-match' => $etag]), '');

        self::assertNotNull($again);
        self::assertSame(304, $again->status);
        self::assertSame('', $again->body);
        self::assertSame(200, $this->assets()->answer($this->request('/app.js', [], 'GET', ['if-none-match' => '"other"']), '')?->status);
    }

    public function testTheAddressWithoutTheWebServerRuleAsksForTheFileThroughIndexPhp(): void
    {
        $response = $this->assets()->answer($this->request('/ts/index.php', ['asset' => 'fonts/plex.woff2', 'v' => '1.2.3']), '/ts');

        self::assertNotNull($response);
        self::assertSame('wOF2....', $response->body);
        self::assertSame('font/woff2', $response->header('Content-Type'));
    }

    public function testTheLicenseOfTheFontsIsPlainText(): void
    {
        self::assertSame('text/plain; charset=utf-8', $this->assets()->answer($this->request('/fonts/OFL.txt'), '')?->header('Content-Type'));
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function notForUs(): iterable
    {
        yield 'a page' => ['/profiles/shop', []];
        yield 'the entry point itself' => ['/index.php', []];
        yield 'a file next to the assets' => ['/.htaccess', []];
        yield 'a php file of the directory' => ['/fonts/../index.php', []];
        yield 'up out of the directory' => ['/../threadsafe-assets-outside.txt', []];
        yield 'up through the parameter' => ['/index.php', ['asset' => '../threadsafe-assets-outside.txt']];
        yield 'up below fonts' => ['/index.php', ['asset' => 'fonts/../index.php']];
        yield 'a hidden file in fonts' => ['/index.php', ['asset' => 'fonts/.htaccess']];
        yield 'a directory' => ['/fonts', []];
        yield 'another script' => ['/index.php', ['asset' => 'index.php']];
        yield 'a name with a slash at the start' => ['/index.php', ['asset' => '/app.css']];
        yield 'a name with a backslash' => ['/index.php', ['asset' => 'fonts\\plex.woff2']];
        yield 'a name with NUL' => ['/index.php', ['asset' => "app.css\0.php"]];
        yield 'encoded dots in the path' => ['/fonts/%2e%2e/index.php', []];
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('notForUs')]
    public function testWhatIsNotOnTheWhitelistIsLeftToTheInterface(string $path, array $query): void
    {
        self::assertNull($this->assets()->answer($this->request($path, $query), ''));
    }

    public function testOnlyReadingRequestsAreAnswered(): void
    {
        self::assertNull($this->assets()->answer($this->request('/app.css', [], 'POST'), ''));
        self::assertSame(200, $this->assets()->answer($this->request('/app.css', [], 'HEAD'), '')?->status);
    }

    public function testAnAddressOutsideTheBaseIsNotOurs(): void
    {
        self::assertNull($this->assets()->answer($this->request('/other/app.css'), '/ts'));
    }

    public function testAWhitelistedNameThatIsNotThereIsNotFound(): void
    {
        $response = $this->assets()->answer($this->request('/fonts/missing.woff2'), '');

        self::assertNotNull($response);
        self::assertSame(404, $response->status);
    }

    public function testASymbolicLinkOutOfTheDirectoryIsNotFollowed(): void
    {
        if (!@symlink(dirname($this->directory) . '/threadsafe-assets-outside.txt', $this->directory . '/fonts/link.woff2')) {
            self::markTestSkipped('Symbolic links cannot be made here.');
        }

        $response = $this->assets()->answer($this->request('/fonts/link.woff2'), '');

        self::assertSame(404, $response?->status);
    }
}
