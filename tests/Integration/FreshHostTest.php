<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Archive\ZipWriter;
use ThreadSafe\Tests\Support\FreshHost;

/**
 * The installer and the update on a host with nothing on it (compose service `fresh`: Apache, PHP 8.4, no Composer, no repository, limits of
 * 5 seconds and 64 MB), through HTTP only (docs/PHASE7.md 4.2). The releases are built by `tools/build-release.php` from this tree; what is
 * tested is what the release workflow would publish.
 */
#[Group('fresh')]
final class FreshHostTest extends IntegrationTestCase
{
    private const string PASSWORD = 'a long password for the fresh host';

    private static string $releases = '';

    public static function setUpBeforeClass(): void
    {
        if (!FreshHost::available()) {
            return;
        }
        self::$releases = sys_get_temp_dir() . '/threadsafe-fresh-releases-' . bin2hex(random_bytes(4));
        $root = dirname(__DIR__, 2);
        foreach (['0.1.0', '0.2.0'] as $version) {
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/build-release.php') . ' --version=' . $version . ' --output=' . escapeshellarg(self::$releases . '/' . $version) . ' 2>&1', $output, $code);
            if ($code !== 0) {
                self::fail("The release $version cannot be built:\n" . implode("\n", $output));
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$releases !== '') {
            exec('rm -rf ' . escapeshellarg(self::$releases));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!FreshHost::available()) {
            self::markTestSkipped('The host "fresh" is not up (docker compose --profile fresh up -d fresh).');
        }
        FreshHost::reset();
    }

    private function package(string $version): string
    {
        return self::$releases . "/$version/threadsafe-$version.zip";
    }

    // ------------------------------------------------------------------------------------------------- helpers

    /**
     * The installation through the wizard, from the key to the removal of the installer.
     *
     * @return array{FreshHost, string} the browser and the key the last page gave for the setup
     */
    private function install(string $place = 'outside', string $version = '0.1.0'): array
    {
        FreshHost::upload(self::$releases . "/$version/install.php");
        $browser = new FreshHost();
        $login = $browser->request('POST', '/install.php', ['action' => 'login', 'key' => FreshHost::KEY, 'plain' => '1']);
        self::assertSame(303, $login['status'], substr($login['body'], 0, 300));
        $check = $browser->get('/install.php?step=check');
        self::assertSame(200, $check['status'], substr(strip_tags($check['body']), 0, 400));
        self::assertStringContainsString('value="install"', $check['body']);

        $install = $browser->installer(['action' => 'install', 'place' => $place] + ($place === 'inside' ? ['inside_ok' => '1'] : []));
        self::assertSame(303, $install['status'], substr(strip_tags($install['body']), 0, 400));
        $answer = ['status' => 'running'];
        for ($i = 0; $i < 200 && $answer['status'] === 'running'; ++$i) {
            $response = $browser->installer(['action' => 'tick', 'format' => 'json']);
            self::assertSame(200, $response['status'], substr($response['body'], 0, 400));
            $answer = json_decode($response['body'], true);
            self::assertIsArray($answer, $response['body']);
        }
        self::assertSame('finished', $answer['status'], (string) ($answer['error'] ?? ''));
        self::assertStringContainsString('is installed', $browser->get('/install.php?step=done')['body']);

        $clean = $browser->installer(['action' => 'cleanup']);
        self::assertStringContainsString('The installer is gone', $clean['body']);
        self::assertSame(1, preg_match('/name="key" value="([0-9a-f]{24})"/', $clean['body'], $m));

        return [$browser, $m[1] ?? ''];
    }

    /** Installs, sets the administrator up and logs in. */
    private function installedAndLoggedIn(string $place = 'outside'): FreshHost
    {
        [$browser, $key] = $this->install($place);
        $form = $browser->request('POST', '/index.php?route=/setup', ['key' => $key]);
        self::assertStringContainsString('The key from the installer is filled in', $form['body']);
        $done = $browser->request('POST', '/setup', ['key' => $key, 'password' => self::PASSWORD, 'repeat' => self::PASSWORD, 'plain' => '1']);
        self::assertSame(200, $done['status'], substr(strip_tags($done['body']), 0, 400));
        $login = $browser->request('POST', '/login', ['user' => 'admin', 'password' => self::PASSWORD, 'plain' => '1']);
        self::assertSame(303, $login['status'], substr(strip_tags($login['body']), 0, 400));
        $page = $browser->get('/system?tab=update');
        self::assertSame(200, $page['status'], substr(strip_tags($page['body']), 0, 400));

        return $browser;
    }

    /** @return array{status: string, error?: string, label?: string, percent?: int}|array<string, mixed> the last answer of the ticks */
    private function ticksToTheEnd(FreshHost $browser): array
    {
        $answer = ['status' => 'running'];
        for ($i = 0; $i < 200 && in_array($answer['status'], ['running', 'busy'], true); ++$i) {
            $response = $browser->interface('/system/update/tick');
            self::assertSame(200, $response['status'], substr($response['body'], 0, 400));
            $answer = json_decode($response['body'], true);
            self::assertIsArray($answer, $response['body']);
            if ($answer['status'] === 'busy') {
                usleep(300000);
            }
        }

        return $answer;
    }

    /**
     * Uploads a package with the checksum and returns the response.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function upload(FreshHost $browser, string $package, ?string $sha = null): array
    {
        $browser->get('/system?tab=update');

        return $browser->interface('/system/update/upload', ['sha256' => $sha ?? (string) hash_file('sha256', $package)], ['package' => [$package, basename($package)]]);
    }

    /**
     * A package changed after it was built: the closure gets the files and the manifest and may change both.
     *
     * @param callable(array<string, string>&, array<string, mixed>&): void $change
     */
    private function variant(string $version, string $name, callable $change, bool $keepManifestStale = false): string
    {
        $zip = ZipReader::open($this->package($version));
        $files = [];
        $manifest = [];
        foreach ($zip->entries() as $entry) {
            $path = substr($entry->name, strlen("threadsafe-$version/"));
            $content = $zip->contents($entry);
            if ($path === 'release.json') {
                $manifest = json_decode($content, true);
            } else {
                $files[$path] = $content;
            }
        }
        $before = $files;
        $change($files, $manifest);
        if (!$keepManifestStale) {
            foreach ($files as $path => $content) {
                if (($before[$path] ?? null) !== $content) {
                    $manifest['files'][$path] = ['size' => strlen($content), 'sha256' => hash('sha256', $content)];
                }
            }
        }
        $target = self::$releases . "/$name.zip";
        @unlink($target);
        $writer = ZipWriter::create($target, 6);
        $writer->addString("threadsafe-$version/release.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 1761955200);
        foreach ($files as $path => $content) {
            $writer->addString("threadsafe-$version/$path", $content, 1761955200, str_starts_with($path, 'bin/') ? 0100755 : 0100644);
        }
        $writer->close();

        return $target;
    }

    private function pointer(): string
    {
        $content = (string) @file_get_contents(FreshHost::home() . '/current.php');
        preg_match('/"release":"([^"]+)"/', $content, $m);

        return $m[1] ?? '';
    }

    // ------------------------------------------------------------------------------------------------- the installer

    public function testWithoutAKeyFileTheInstallerOnlyExplains(): void
    {
        FreshHost::upload(self::$releases . '/0.1.0/install.php', key: false);

        $response = (new FreshHost())->get('/install.php');

        self::assertSame(403, $response['status']);
        self::assertStringContainsString('does nothing until you give it a key', $response['body']);
        self::assertSame(['install.php'], array_values(array_diff(scandir(FreshHost::WWW . '/html') ?: [], ['.', '..'])), 'nothing was written');
    }

    public function testAWrongKeyIsRefused(): void
    {
        FreshHost::upload(self::$releases . '/0.1.0/install.php');

        $response = (new FreshHost())->request('POST', '/install.php', ['action' => 'login', 'key' => 'not the key', 'plain' => '1']);

        self::assertSame(403, $response['status']);
        self::assertStringContainsString('This is not the key', $response['body']);
    }

    public function testTheInstallerInstallsThroughTheBrowserUnderTightLimits(): void
    {
        [$browser, $key] = $this->install();

        self::assertSame(['.htaccess', 'index.php'], array_values(array_diff(scandir(FreshHost::WWW . '/html') ?: [], ['.', '..'])), 'the installer, its key and its directory are gone');
        self::assertSame('0.1.0', $this->pointer());
        self::assertFileExists(FreshHost::home() . '/releases/0.1.0/vendor/autoload.php');
        self::assertFileDoesNotExist(FreshHost::home() . '/releases/0.1.0.partial');
        self::assertSame(24, strlen($key));
        self::assertFileExists(FreshHost::home() . '/data/setup-key.php');
        self::assertSame(['dataFormat' => 1, 'program' => '0.1.0'], json_decode((string) file_get_contents(FreshHost::home() . '/data/version.json'), true));
        // the home is next to the document root: nothing of the program is on the web
        foreach (['/threadsafe/current.php', '/threadsafe/releases/0.1.0/src/Core/Version.php', '/threadsafe/data/setup-key.php'] as $path) {
            $answer = (new FreshHost())->get($path);
            self::assertNotSame(200, $answer['status'], "$path is not served");
            self::assertStringNotContainsString('class Version', $answer['body']);
        }
        // until the setup there is nothing but the setup: every other address leads there
        $login = (new FreshHost())->get('/login');
        self::assertSame(303, $login['status']);
        self::assertStringEndsWith('/setup', $login['headers']['location'] ?? '');
    }

    public function testTheInterfaceWorksAfterTheInstallationWithItsFilesAndTheLimits(): void
    {
        $browser = $this->installedAndLoggedIn();

        $page = $browser->get('/system');
        self::assertSame(200, $page['status']);
        $text = html_entity_decode(strip_tags($page['body']));
        self::assertStringContainsString('from a release; the home is "/var/www/threadsafe"', $text);
        self::assertMatchesRegularExpression('/memory_limit 64M, max_execution_time 5 s/', $text, 'the host has the limits of the test');
        self::assertSame(1, preg_match('#href="(/app\.css\?v=0\.1\.0)"#', $page['body'], $m), 'the pages ask for the files with the version');

        $css = (new FreshHost())->get($m[1] ?? '/app.css?v=0.1.0');
        self::assertSame(200, $css['status']);
        self::assertStringContainsString('text/css', $css['headers']['content-type'] ?? '');
        self::assertSame('public, max-age=31536000, immutable', $css['headers']['cache-control'] ?? '');
        self::assertSame(200, (new FreshHost())->get('/fonts/ibm-plex-sans-latin-400-normal.woff2?v=0.1.0')['status']);
        self::assertNotSame(200, (new FreshHost())->get('/index.php?asset=../src/Core/Version.php')['status'], 'a name outside the whitelist is not a file');
    }

    public function testTheWeakerLayoutIsClosedByItsHtaccess(): void
    {
        $this->install('inside');

        self::assertFileExists(FreshHost::WWW . '/html/threadsafe-home/current.php');
        $closed = [
            '/threadsafe-home/current.php',
            '/threadsafe-home/data/setup-key.php',
            '/threadsafe-home/releases/0.1.0/src/Core/Version.php',
            '/threadsafe-home/releases/0.1.0/vendor/autoload.php',
            '/threadsafe-home/bin/threadsafe',
        ];
        foreach ($closed as $path) {
            self::assertSame(403, (new FreshHost())->get($path)['status'], "$path must not be served");
        }
        // the interface of this layout is the same stub in the document root
        self::assertSame(303, (new FreshHost())->get('/login')['status']);
    }

    // ------------------------------------------------------------------------------------------------- the update

    public function testAnUpdateThroughTheInterfaceSwitchesAndAHandRollbackGoesBack(): void
    {
        $browser = $this->installedAndLoggedIn();
        $package = $this->package('0.2.0');

        $upload = $this->upload($browser, $package);
        self::assertSame(303, $upload['status']);
        $page = $browser->get('/system?tab=update');
        self::assertStringContainsString('data-tick-url', $page['body']);

        $answer = $this->ticksToTheEnd($browser);

        self::assertSame('finished', $answer['status'], (string) ($answer['error'] ?? ''));
        self::assertSame('0.2.0', $this->pointer());
        self::assertDirectoryExists(FreshHost::home() . '/releases/0.1.0');
        self::assertDirectoryExists(FreshHost::home() . '/releases/0.2.0');
        $result = strip_tags($browser->get('/system?tab=update')['body']);
        self::assertStringContainsString('Updated from 0.1.0 to 0.2.0', $result);
        self::assertStringContainsString('answered as version 0.2.0', $result, 'the new version was asked, through the host\'s own address, and answered');
        self::assertStringNotContainsString('could not be started to check it', $result);
        self::assertDirectoryExists(FreshHost::home() . '/data/update-backup');
        // the same session works on the new version, and the pages ask for its files
        $system = $browser->get('/system');
        self::assertSame(200, $system['status']);
        self::assertStringContainsString('/app.css?v=0.2.0', $system['body']);
        self::assertSame(200, (new FreshHost())->get('/app.css?v=0.2.0')['status']);
        self::assertStringContainsString('"event":"update.applied"', (string) file_get_contents(FreshHost::home() . '/data/audit.log'));

        $browser->interface('/system/update/discard');
        self::assertStringContainsString('Go back to 0.1.0', $browser->get('/system?tab=update')['body']);
        $back = $browser->interface('/system/update/rollback');
        self::assertSame(303, $back['status']);
        self::assertSame('0.1.0', $this->pointer());
        self::assertStringContainsString('/app.css?v=0.1.0', $browser->get('/system')['body']);
    }

    public function testAnUpdateWhoseNewVersionDoesNotStartIsTakenBack(): void
    {
        $browser = $this->installedAndLoggedIn();
        $broken = $this->variant('0.2.0', 'broken', static function (array &$files): void {
            $files['src/Admin/Entry.php'] = "<?php\ndeclare(strict_types=1);\nnamespace ThreadSafe\\Admin;\nfinal class Entry\n{\n    public static function run(): void\n    {\n        throw new \\RuntimeException('this version cannot start');\n    }\n}\n";
        });
        $profile = FreshHost::home() . '/data/profiles';
        @mkdir($profile, 0700, true);
        file_put_contents($profile . '/shop.json', '{"id": "shop"}');
        FreshHost::ownedByTheWebServer(FreshHost::home() . '/data');

        $this->upload($browser, $broken);
        $answer = $this->ticksToTheEnd($browser);

        self::assertSame('finished', $answer['status'], (string) ($answer['error'] ?? ''));
        self::assertSame('0.1.0', $this->pointer(), 'the pointer is back');
        self::assertDirectoryDoesNotExist(FreshHost::home() . '/releases/0.2.0', 'the release that did not start is removed');
        self::assertFileExists($profile . '/shop.json', 'the state files are as they were');
        $page = strip_tags($browser->get('/system?tab=update')['body']);
        self::assertStringContainsString('did not start, so it was taken back', $page);
        self::assertStringContainsString('"event":"update.rolled_back"', (string) file_get_contents(FreshHost::home() . '/data/audit.log'));
        self::assertSame(200, $browser->get('/system')['status'], 'the old version serves the same session');
    }

    public function testWhatIsNotRightIsRefusedAndTheInstallationStaysAsItWas(): void
    {
        $browser = $this->installedAndLoggedIn();
        $good = $this->package('0.2.0');
        $cases = [
            'a wrong checksum' => [$good, str_repeat('a', 64), 'The checksum of the file is'],
            'a file that is no package' => [self::$releases . '/garbage.zip', null, 'damaged'],
            'a file changed after the build' => [$this->variant('0.2.0', 'tampered', static function (array &$files): void {
                $files['LICENSE'] .= "\nchanged";
            }, true), null, 'does not match its hash'],
            'a host that cannot run it' => [$this->variant('0.2.0', 'toonew', static function (array &$files, array &$manifest): void {
                $manifest['php'] = '99.0';
            }), null, 'needs PHP 99.0 or newer'],
        ];
        file_put_contents(self::$releases . '/garbage.zip', 'this is not a zip archive');

        foreach ($cases as $what => [$package, $sha, $message]) {
            $this->upload($browser, $package, $sha);
            $answer = $this->ticksToTheEnd($browser);
            self::assertSame('failed', $answer['status'], $what);
            self::assertStringContainsString($message, (string) $answer['error'], $what);
            self::assertSame('0.1.0', $this->pointer(), $what);
            self::assertSame([], glob(FreshHost::home() . '/releases/*.partial') ?: [], "$what leaves no half-unpacked release");
            self::assertSame(200, $browser->get('/system')['status'], "$what: the installation works");
            $browser->interface('/system/update/discard');
        }
    }

    public function testAnUpdateWaitsForARunningBackupAndGoesOnWhenItIsOver(): void
    {
        $browser = $this->installedAndLoggedIn();
        @mkdir(FreshHost::home() . '/data/locks', 0700, true);
        $lockFile = FreshHost::home() . '/data/locks/backup.lock';
        file_put_contents($lockFile, "pid 1 backup of shop\n");
        FreshHost::ownedByTheWebServer(FreshHost::home() . '/data');
        chmod($lockFile, 0666);
        $lock = fopen($lockFile, 'c+b');
        self::assertNotFalse($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        $this->upload($browser, $this->package('0.2.0'));
        $answer = $this->ticksToTheEnd($browser);
        self::assertSame('failed', $answer['status']);
        self::assertStringContainsString('backup of shop', (string) $answer['error']);
        self::assertSame('0.1.0', $this->pointer());

        flock($lock, LOCK_UN);
        fclose($lock);
        $browser->get('/system?tab=update');
        $browser->interface('/system/update/retry');
        $answer = $this->ticksToTheEnd($browser);

        self::assertSame('finished', $answer['status'], (string) ($answer['error'] ?? ''));
        self::assertSame('0.2.0', $this->pointer());
    }
}
