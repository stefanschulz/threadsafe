<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Build;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Build\Bundler;
use ThreadSafe\Build\InstallBuilder;
use ThreadSafe\Build\InstallStub;
use ThreadSafe\Build\StubNotice;
use ThreadSafe\Build\ReleaseManifest;
use ThreadSafe\Tests\Support\ReleasePackage;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * The web installer built from the real sources around a small package: its shape, the stub, and - in a fresh PHP process with nothing
 * but the file itself - the whole installation through the wizard. That last test is what shows that the code in the file needs no class
 * that was left out of it.
 */
final class InstallBuilderTest extends TestCase
{
    use TempDirectory;

    private static string $directory = '';

    private static string $installer = '';

    /** @var array{bytes: int, codeBytes: int, files: int} */
    private static array $result = ['bytes' => 0, 'codeBytes' => 0, 'files' => 0];

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir() . '/threadsafe-test-installer-' . bin2hex(random_bytes(4));
        mkdir(self::$directory . '/site', 0777, true);
        $package = ReleasePackage::build(self::$directory, '0.1.0');
        self::$installer = self::$directory . '/site/install.php';
        self::$result = (new InstallBuilder(dirname(__DIR__, 3)))->build(self::$installer, $package, '0.1.0');
    }

    public static function tearDownAfterClass(): void
    {
        (new self('cleanup'))->removeTree(self::$directory);
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . '/' . $entry);
                }
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }

    /** A copy of the installer in a new document root, for the tests that run it. */
    private function site(bool $withKey = true): string
    {
        $site = $this->tempDirectory() . '/public_html';
        mkdir($site);
        copy(self::$installer, $site . '/install.php');
        if ($withKey) {
            file_put_contents($site . '/install-key.php', "<?php return 'a long secret key';");
        }

        return $site;
    }

    /**
     * @return array{string, string, int} output, errors, exit code
     */
    private function php(string $code, string ...$arguments): array
    {
        $process = proc_open(array_values([PHP_BINARY, '-r', $code, ...$arguments]),[1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);

        return [$output, $errors, proc_close($process)];
    }

    public function testTheFileLeadsWithTheStubAndHoldsTheCodeThenThePackage(): void
    {
        $file = (string) file_get_contents(self::$installer);
        $stub = InstallStub::source('0.1.0');

        self::assertTrue(str_starts_with($file, $stub));
        self::assertSame(self::$result['bytes'], strlen($file));
        self::assertSame(1, preg_match('/^(\d+)\n/', substr($file, strlen($stub), 20), $m));
        $header = $m[0] ?? '';
        $length = (int) ($m[1] ?? 0);
        $code = gzinflate(substr($file, strlen($stub) + strlen($header), $length));
        self::assertIsString($code);
        self::assertStringContainsString('namespace ThreadSafe\\Install {', $code);
        self::assertStringNotContainsString('namespace ThreadSafe\\Admin', $code, 'the web interface is not part of the installer');
        self::assertStringContainsString('final readonly class ReleaseManifest', $code, 'the manifest, which the installer reads');
        self::assertStringNotContainsString('final class ReleaseBuilder', $code, 'but not the build tools');
        self::assertLessThan(600 * 1024, strlen($file));
    }

    public function testTheFileIsAlsoTheArchiveOfThePackage(): void
    {
        $zip = ZipReader::open(self::$installer);
        $first = $zip->entries()->current();

        self::assertSame('threadsafe-0.1.0/release.json', $first->name);
        $manifest = ReleaseManifest::fromJson($zip->contents($first));
        self::assertSame([], $manifest->problems($zip, 'threadsafe-0.1.0/'));
        if ((string) shell_exec('command -v unzip') !== '') {
            exec('unzip -tq ' . escapeshellarg(self::$installer) . ' 2>&1', $output, $code);
            self::assertSame(0, $code, implode("\n", $output));
        }
    }

    public function testTheStubUsesOnlyWhatOldPhpCanRead(): void
    {
        $stub = InstallStub::source('test');
        $tokens = token_get_all(substr($stub, 0, (int) strpos($stub, '__halt_compiler') + strlen('__halt_compiler')));
        $forbidden = [
            T_NAMESPACE => 'namespace', T_NS_SEPARATOR => 'a name with a backslash', T_NAME_QUALIFIED => 'a qualified name', T_NAME_FULLY_QUALIFIED => 'a qualified name',
            T_FN => 'fn', T_DIR => '__DIR__', T_COALESCE => '??', T_POW => '**', T_USE => 'use', T_CLASS => 'class', T_TRAIT => 'trait',
            T_YIELD => 'yield', T_FINALLY => 'finally', T_GOTO => 'goto', T_SPACESHIP => '<=>', T_ELLIPSIS => '...', T_PUBLIC => 'visibility', T_STATIC => 'static',
            T_FUNCTION => 'a function or closure',
        ];
        $previous = null;
        foreach ($tokens as $token) {
            if (is_array($token) && isset($forbidden[$token[0]])) {
                self::fail('The stub uses ' . $forbidden[$token[0]] . ' (line ' . $token[2] . '), which old PHP cannot parse.');
            }
            if ($token === '[' && ($previous === '=' || $previous === '(' || $previous === ',' || (is_array($previous) && in_array($previous[0], [T_RETURN, T_DOUBLE_ARROW], true)))) {
                self::fail('The stub uses a short array.');
            }
            if ($token === '?') {
                self::fail('The stub uses ?: or a nullable type.');
            }
            if (!is_array($token) || $token[0] !== T_WHITESPACE) {
                $previous = $token;
            }
        }
        self::assertStringContainsString("'ThreadSafe\\Install\\Entry::run'", $stub, 'the entry is called by name from a string');
        self::assertLessThan(strpos($stub, 'mkdir'), strpos($stub, "install-key.php'"), 'the key check comes before the first directory is made');
    }

    public function testEveryClassOfTheInstallerIsInItsCode(): void
    {
        $builder = new InstallBuilder(dirname(__DIR__, 3));
        $expected = (new Bundler())->declared($builder->sourceFiles());
        self::assertGreaterThan(150, count($expected));
        $bundle = $this->tempDirectory() . '/bundle.php';
        file_put_contents($bundle, $builder->bundle());
        token_get_all((string) file_get_contents($bundle), TOKEN_PARSE);

        [$output, $errors] = $this->php('$m = []; require $argv[1]; foreach (json_decode($argv[2]) as $n) { if (!class_exists($n, false) && !interface_exists($n, false) && !trait_exists($n, false) && !enum_exists($n, false)) { $m[] = $n; } } echo json_encode($m);', $bundle, (string) json_encode($expected));

        self::assertSame('', $errors);
        self::assertSame('[]', $output, 'classes the bundle does not declare');
    }

    public function testWithoutAKeyFileTheInstallerOnlyExplainsAndWritesNothing(): void
    {
        $site = $this->site(withKey: false);

        [$output] = $this->php('$_SERVER["REQUEST_METHOD"] = "GET"; include $argv[1];', $site . '/install.php');

        self::assertStringContainsString('does nothing until you give it a key', $output);
        self::assertStringContainsString("<pre><code>&lt;?php return 'a secret you choose';</code></pre>", $output);
        self::assertStringContainsString('<code>install-key.php</code> next to <code>install.php</code>', $output);
        self::assertSame(['install.php'], array_values(array_diff(scandir($site) ?: [], ['.', '..'])), 'nothing was written');
    }

    public function testTheNoticeOfTheStubIsAPageInTheStyleOfTheWizard(): void
    {
        $site = $this->site(withKey: false);

        [$output, $errors] = $this->php('$_SERVER["REQUEST_METHOD"] = "GET"; include $argv[1];', $site . '/install.php');

        self::assertSame('', $errors);
        self::assertStringStartsWith('<!doctype html>', $output);
        self::assertStringContainsString('<title>No access key yet - ThreadSafe installer</title>', $output);
        self::assertStringContainsString('<h1><svg class="logo"', $output, 'the logo');
        self::assertStringContainsString('<span>ThreadSafe installer</span>', $output);
        self::assertStringContainsString('--brass-fill:#e0a13d', $output, 'the palette of the wizard');
        self::assertStringContainsString('<h2>No access key yet</h2>', $output);
        self::assertStringNotContainsString(' style=', $output, 'the policy of the page allows a style element, no style attribute');
        self::assertSame(1, substr_count($output, '<script'), 'one script: the copy button');
        self::assertSame(1, preg_match('/<script nonce="([0-9a-f]{32})">/', $output, $nonce));
        self::assertStringContainsString("say('Copy to clipboard',COPY)", $output, 'the words are the tooltip and the name of the button');
        self::assertStringContainsString('button.title=text', $output);
        self::assertStringNotContainsString('textContent=', $output, 'the button shows a symbol and no text');
        self::assertStringContainsString("row.className='copyrow'", $output, 'the button stands right of the block');
        self::assertStringContainsString('navigator.clipboard.writeText', $output);
    }

    public function testOnlyTheScriptWithTheNonceOfTheAnswerMayRun(): void
    {
        // a shell keeps no headers, so the policy is read from the source of the block: the same nonce goes into the header and into the script tag
        $source = StubNotice::source('installer');

        self::assertStringContainsString("default-src 'none'; style-src 'unsafe-inline'; img-src data:; script-src 'nonce-\" . \$threadsafeNonce . \"'", $source);
        self::assertSame(1, substr_count($source, '<script nonce="'), 'one script, with the nonce');
        self::assertStringContainsString('$threadsafeNonce . ', $source);
        self::assertStringNotContainsString("'unsafe-inline'; img-src data:; script-src 'unsafe", $source);
        self::assertStringNotContainsString('script-src \'unsafe-inline\'', $source, 'no script without the nonce');
    }

    public function testInAShellTheNoticeIsPlainText(): void
    {
        // the block of the stub itself, run in a shell with a notice set (the stub's other paths do not reach it there)
        [$output, $errors, $code] = $this->php('$threadsafeNotice = array("500 X", "Title", "<p>Hello <code>world</code></p><pre><code>&lt;?php return 1;</code></pre>"); eval($argv[1]);', StubNotice::source('installer'));

        self::assertSame('', $errors);
        self::assertSame(1, $code);
        self::assertSame("Hello world\n\n<?php return 1;\n\n\n", $output, 'the text of the notice, no markup');
    }

    public function testWithAKeyFileItAsksForTheKey(): void
    {
        $site = $this->site();

        [$output, $errors] = $this->php('$_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["REMOTE_ADDR"] = "127.0.0.1"; $_SERVER["REQUEST_URI"] = "/install.php"; include $argv[1];', $site . '/install.php');

        self::assertSame('', $errors);
        self::assertStringContainsString('Key from <code>install-key.php</code>', $output);
        self::assertDirectoryExists($site . '/.threadsafe-install');
        self::assertFileExists($site . '/.threadsafe-install/.htaccess');
    }

    public function testInAShellItSaysWhatToDoInstead(): void
    {
        [, $errors, $code] = $this->php('include $argv[1];', $this->site() . '/install.php');

        self::assertSame(1, $code);
        self::assertStringContainsString('open it in a browser', $errors);
        self::assertStringContainsString('unzip install.php', $errors);
    }

    public function testTheWholeInstallationRunsThroughTheWizardWithNothingButTheFile(): void
    {
        $site = $this->site();
        // the first request unpacks the code next to the file
        $this->php('$_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["REMOTE_ADDR"] = "127.0.0.1"; $_SERVER["REQUEST_URI"] = "/install.php"; include $argv[1];', $site . '/install.php');
        $bundles = glob($site . '/.threadsafe-install/bundle-*.php') ?: [];
        self::assertCount(1, $bundles);

        $flow = <<<'PHP'
require $argv[1];
$script = $argv[2];
$wizard = new ThreadSafe\Install\Wizard($script, null, static fn () => new ThreadSafe\Engine\Budget(0.0));
$request = static fn (string $method, array $query, array $post, ?string $token) => new ThreadSafe\Web\Request($method, $query, $post, $token === null ? [] : [ThreadSafe\Install\Wizard::COOKIE => $token], false, '127.0.0.1', '/install.php');
$out = [];
$login = $wizard->handle($request('POST', [], ['action' => 'login', 'key' => 'a long secret key'], null));
preg_match('/threadsafe_install=([0-9a-f]{64})/', (string) $login->header('Set-Cookie'), $m);
$token = $m[1];
$page = $wizard->handle($request('GET', ['step' => 'check'], [], $token));
preg_match('/name="csrf" value="([0-9a-f]+)"/', $page->body, $m);
$csrf = $m[1];
$out['check'] = [$page->status, str_contains($page->body, 'value="install"'), str_contains($page->body, 'Next to the document root')];
$install = $wizard->handle($request('POST', [], ['csrf' => $csrf, 'action' => 'install', 'place' => 'outside'], $token));
$out['install'] = [$install->status, $install->header('Location')];
$ticks = 0;
do {
    $answer = json_decode($wizard->handle($request('POST', [], ['csrf' => $csrf, 'action' => 'tick', 'format' => 'json'], $token))->body, true);
    $ticks++;
} while ($answer['status'] === 'running' && $ticks < 200);
$out['ticks'] = [$ticks, $answer['status'], $answer['percent']];
$done = $wizard->handle($request('GET', ['step' => 'done'], [], $token));
$out['done'] = [$done->status, str_contains($done->body, 'is installed')];
$clean = $wizard->handle($request('POST', [], ['csrf' => $csrf, 'action' => 'cleanup'], $token));
preg_match('/name="key" value="([0-9a-f]+)"/', $clean->body, $m);
$out['cleanup'] = [$clean->status, str_contains($clean->body, 'The installer is gone'), strlen($m[1] ?? '')];
echo json_encode($out);
PHP;
        [$output, $errors] = $this->php($flow, $bundles[0], $site . '/install.php');

        self::assertSame('', $errors);
        $out = json_decode($output, true);
        self::assertIsArray($out, $output);
        self::assertSame([200, true, true], $out['check']);
        self::assertSame([303, '?step=run'], $out['install']);
        self::assertSame('finished', $out['ticks'][1]);
        self::assertSame(100, $out['ticks'][2]);
        self::assertGreaterThan(5, $out['ticks'][0]);
        self::assertSame([200, true], $out['done']);
        self::assertSame([200, true, 24], $out['cleanup'], 'the installer is gone and the page has the key for the setup');
        self::assertSame(['.htaccess', 'index.php'], array_values(array_diff(scandir($site) ?: [], ['.', '..'])), 'the installer, its key and its directory are gone; the stub is there');
        $home = dirname($site) . '/threadsafe';
        self::assertFileExists($home . '/current.php');
        self::assertFileExists($home . '/releases/0.1.0/vendor/autoload.php');
        self::assertFileExists($home . '/data/setup-key.php');
    }
}
