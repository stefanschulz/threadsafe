<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Admin\AdminStore;
use ThreadSafe\Admin\AuditLog;
use ThreadSafe\Admin\Config;
use ThreadSafe\Admin\Kernel;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\Request;
use ThreadSafe\Web\Response;

/**
 * The web interface as a whole, without a web server: a request goes in, a response comes out. The "browser" of this test keeps
 * the session cookie and the CSRF token like a real one.
 */
final class KernelTest extends TestCase
{
    use TempDirectory;

    private const string PASSWORD = 'correct horse battery';

    private string $data;

    private int $now = 1_800_000_000;

    private string $cookie = '';

    private string $csrf = '';

    protected function setUp(): void
    {
        $this->data = $this->tempDirectory() . '/data';
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function kernel(string $base = ''): Kernel
    {
        return new Kernel($this->data, $base, fn (): int => $this->now);
    }

    /** An installation that is switched on and has an administrator. */
    private function installed(): void
    {
        mkdir($this->data, 0700, true);
        (new AdminStore($this->data . '/admin.json'))->setPassword('admin', self::PASSWORD);
        Config::defaults()->with('web.enabled', true)->save($this->data . '/config.json');
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     */
    private function send(string $method, string $path, array $fields = [], array $headers = [], string $address = '127.0.0.1', bool $https = false, string $base = ''): Response
    {
        $headers += ['host' => 'ts.example'];
        $request = new Request($method, str_contains($path, '?') ? $this->query($path) : [], $fields, $this->cookie === '' ? [] : ['threadsafe_session' => $this->cookie], $https, $address, strtok($path, '?') ?: '/', $headers);
        $response = $this->kernel($base)->handle($request);

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

    /** @return array<string, string> */
    private function query(string $path): array
    {
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $strings = [];
        foreach ($query as $name => $value) {
            if (is_string($value)) {
                $strings[(string) $name] = $value;
            }
        }

        return $strings;
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(string $path, array $fields = [], bool $withToken = true): Response
    {
        return $this->send('POST', $path, $fields + ($withToken ? ['csrf' => $this->csrf] : []));
    }

    private function login(): Response
    {
        $this->send('GET', '/login');

        return $this->post('/login', ['user' => 'admin', 'password' => self::PASSWORD], withToken: false);
    }

    // ------------------------------------------------------------------------------------------------- off by default

    public function testAFreshInstallationIsNotThereAtAllAndWritesNothing(): void
    {
        foreach (['/', '/login', '/setup', '/profiles/x'] as $path) {
            $response = $this->send('GET', $path);
            self::assertSame(404, $response->status, $path);
            self::assertStringNotContainsStringIgnoringCase('log in', $response->body, 'no hint that there is a login');
        }
        self::assertDirectoryDoesNotExist($this->data, 'not even the data directory is made');
    }

    public function testSwitchedOffAfterTheSetupIsNotThereEither(): void
    {
        $this->installed();
        Config::defaults()->save($this->data . '/config.json');

        self::assertSame(404, $this->send('GET', '/login')->status);
        self::assertSame(404, $this->send('POST', '/login', ['user' => 'admin', 'password' => self::PASSWORD])->status);
    }

    public function testSwitchedOnWithoutAnAdministratorSaysHowToSetOne(): void
    {
        mkdir($this->data, 0700, true);
        Config::defaults()->with('web.enabled', true)->save($this->data . '/config.json');

        $response = $this->send('GET', '/');

        self::assertSame(403, $response->status);
        self::assertStringContainsString('admin password', $response->body);
        self::assertStringContainsString('setup-key.php', $response->body);
        self::assertSame(403, $this->send('POST', '/login', ['password' => 'x'])->status, 'nobody can log in');
    }

    public function testADamagedConfigurationIsReportedNotIgnored(): void
    {
        mkdir($this->data, 0700, true);
        file_put_contents($this->data . '/config.json', '{"web": {"enabled": "maybe"}}');

        $response = $this->send('GET', '/');

        self::assertSame(500, $response->status);
        self::assertStringContainsString('web.enabled', $response->body);
    }

    // ------------------------------------------------------------------------------------------------- setup in the browser

    public function testTheSetupIsOpenOnlyWithTheKeyFileAndOnlyUntilItHasRun(): void
    {
        mkdir($this->data, 0700, true);
        file_put_contents($this->data . '/setup-key.php', "<?php return 'a secret to open it';");

        $page = $this->send('GET', '/setup');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Set up the administrator', $page->body);
        self::assertSame(303, $this->send('GET', '/login')->status, 'everything else leads to the setup');
        self::assertStringEndsWith('/setup', (string) $this->send('GET', '/')->header('Location'));

        $wrong = $this->send('POST', '/setup', ['key' => 'nope', 'password' => 'a long new password', 'repeat' => 'a long new password']);
        self::assertSame(403, $wrong->status);
        self::assertFileDoesNotExist($this->data . '/admin.json');

        $mismatch = $this->send('POST', '/setup', ['key' => 'a secret to open it', 'password' => 'a long new password', 'repeat' => 'another long one']);
        self::assertSame(400, $mismatch->status);
        $short = $this->send('POST', '/setup', ['key' => 'a secret to open it', 'password' => 'short', 'repeat' => 'short']);
        self::assertSame(400, $short->status);
        self::assertFileDoesNotExist($this->data . '/admin.json');

        $done = $this->send('POST', '/setup', ['key' => 'a secret to open it', 'password' => 'a long new password', 'repeat' => 'a long new password']);

        self::assertSame(200, $done->status);
        self::assertStringContainsString('Setup done', $done->body);
        self::assertFileDoesNotExist($this->data . '/setup-key.php', 'the key file is used up');
        self::assertTrue((new AdminStore($this->data . '/admin.json'))->verify('admin', 'a long new password'));
        self::assertTrue(Config::load($this->data . '/config.json')->enabled());
        self::assertSame(303, $this->send('GET', '/setup')->status, 'the setup is closed now: an address that does not exist leads to the login');
        self::assertSame(200, $this->send('GET', '/login')->status);
        self::assertStringNotContainsString('a long new password', (string) file_get_contents($this->data . '/audit.log'));
        self::assertSame('setup', (new AuditLog($this->data . '/audit.log'))->recent(1)[0]['event'] ?? null);
    }

    public function testWrongSetupKeysAreThrottled(): void
    {
        mkdir($this->data, 0700, true);
        file_put_contents($this->data . '/setup-key.php', "<?php return 'a secret to open it';");
        $fields = ['key' => 'guess', 'password' => 'a long new password', 'repeat' => 'a long new password'];

        $statuses = [];
        for ($i = 0; $i < 5; ++$i) {
            $statuses[] = $this->send('POST', '/setup', $fields)->status;
        }
        $right = $this->send('POST', '/setup', ['key' => 'a secret to open it'] + $fields);

        self::assertSame([403, 403, 403, 429, 429], $statuses);
        self::assertSame(429, $right->status, 'even the right key waits');
        self::assertFileDoesNotExist($this->data . '/admin.json');
        $this->now += 400;
        self::assertSame(200, $this->send('POST', '/setup', ['key' => 'a secret to open it'] + $fields)->status);
    }

    // ------------------------------------------------------------------------------------------------- login and logout

    public function testTheLoginPageIsReachableAndEveryOtherPageIsNot(): void
    {
        $this->installed();

        $login = $this->send('GET', '/login');
        self::assertSame(200, $login->status);
        self::assertStringContainsString('name="password"', $login->body);

        $dashboard = $this->send('GET', '/');
        self::assertSame(303, $dashboard->status);
        self::assertStringEndsWith('/login', (string) $dashboard->header('Location'));
        $unknown = $this->send('GET', '/profiles/shop/anything');
        self::assertSame(303, $unknown->status, 'an unknown address behind the login is not revealed either');
    }

    public function testAScriptRequestWithoutASessionGetsJsonNotARedirect(): void
    {
        $this->installed();

        $response = $this->send('POST', '/runs/x/tick', [], ['x-csrf-token' => 'whatever']);

        self::assertSame(401, $response->status);
        self::assertSame('login', json_decode($response->body, true)['status'] ?? null);
    }

    public function testTheRightPasswordLogsInWithAHardenedCookie(): void
    {
        $this->installed();

        $response = $this->login();

        self::assertSame(303, $response->status);
        $cookie = $response->headers('Set-Cookie')[0] ?? '';
        self::assertMatchesRegularExpression('/^threadsafe_session=[0-9a-f]{64}; Path=\/; HttpOnly; SameSite=Strict$/', $cookie);
        $dashboard = $this->send('GET', '/');
        self::assertSame(200, $dashboard->status);
        self::assertStringContainsString('Dashboard', $dashboard->body);
        self::assertStringContainsString('Log out', $dashboard->body);
    }

    public function testOverHttpsTheCookieIsSecureAndInADirectoryItIsLimitedToIt(): void
    {
        $this->installed();
        $this->send('GET', '/login', base: '/ts/public');

        $response = $this->send('POST', '/login', ['user' => 'admin', 'password' => self::PASSWORD], https: true, base: '/ts/public', address: '203.0.113.5');

        self::assertSame(303, $response->status);
        self::assertSame('/ts/public/', $response->header('Location'));
        self::assertStringContainsString('Path=/ts/public/; HttpOnly; SameSite=Strict; Secure', $response->headers('Set-Cookie')[0] ?? '');
    }

    public function testAWrongPasswordGetsTheSameAnswerAsAnUnknownUserAndIsRecordedWithoutThePassword(): void
    {
        $this->installed();

        $wrong = $this->post('/login', ['user' => 'admin', 'password' => 'wrong password one'], withToken: false);
        $unknown = $this->post('/login', ['user' => 'nobody', 'password' => 'wrong password two'], withToken: false);

        self::assertSame(403, $wrong->status);
        self::assertSame(403, $unknown->status);
        self::assertStringContainsString('The user name or the password is wrong.', $wrong->body);
        self::assertStringContainsString('The user name or the password is wrong.', $unknown->body);
        $log = (string) file_get_contents($this->data . '/audit.log');
        self::assertStringContainsString('login.failed', $log);
        self::assertStringNotContainsString('wrong password', $log);
    }

    public function testWrongPasswordsAreThrottledAndTheRightOneWaitsToo(): void
    {
        $this->installed();

        $statuses = [];
        for ($i = 0; $i < 5; ++$i) {
            $statuses[] = $this->post('/login', ['user' => 'admin', 'password' => 'wrong password here'], withToken: false)->status;
        }
        $right = $this->post('/login', ['user' => 'admin', 'password' => self::PASSWORD], withToken: false);

        self::assertSame([403, 403, 403, 429, 429], $statuses);
        self::assertSame(429, $right->status);
        self::assertSame('5', $right->header('Retry-After'), 'attempts during the wait are not counted, so it stays at the wait of three failures');
        $this->now += 11;
        self::assertSame(303, $this->post('/login', ['user' => 'admin', 'password' => self::PASSWORD], withToken: false)->status);
    }

    public function testOverPlainHttpTheLoginNeedsAConfirmationUnlessItIsTheLocalMachine(): void
    {
        $this->installed();
        $fields = ['user' => 'admin', 'password' => self::PASSWORD];

        $page = $this->send('GET', '/login', address: '203.0.113.5');
        self::assertStringContainsString('not encrypted', $page->body);
        self::assertSame(403, $this->send('POST', '/login', $fields, address: '203.0.113.5')->status);
        self::assertSame(303, $this->send('POST', '/login', $fields + ['plain' => '1'], address: '203.0.113.5')->status);
        self::assertStringNotContainsString('not encrypted', $this->send('GET', '/login', address: '127.0.0.1', https: true)->body);
    }

    public function testEveryLoginMakesANewSessionAndTheOldOneStopsWorking(): void
    {
        $this->installed();
        $this->login();
        $first = $this->cookie;

        $this->login();

        self::assertNotSame($first, $this->cookie);
        $this->cookie = $first;
        self::assertSame(303, $this->send('GET', '/')->status, 'the old session is gone');
    }

    public function testLoggingOutEndsTheSessionAndNeedsAPostWithTheToken(): void
    {
        $this->installed();
        $this->login();
        $this->send('GET', '/');

        self::assertSame(405, $this->send('GET', '/logout')->status, 'a link must not log out');
        self::assertSame(403, $this->post('/logout', withToken: false)->status, 'a forged form must not log out');
        self::assertSame(200, $this->send('GET', '/')->status, 'still logged in');

        $response = $this->post('/logout');

        self::assertSame(303, $response->status);
        self::assertStringContainsString('Max-Age=0', $response->headers('Set-Cookie')[0] ?? '');
        self::assertSame(303, $this->send('GET', '/')->status);
        self::assertSame([], glob($this->data . '/sessions/*.json'), 'no session file is left');
    }

    public function testTheSessionEndsAfterTheIdleTimeAndAfterTheAbsoluteLifetime(): void
    {
        $this->installed();
        $this->login();
        $this->now += 3600;
        self::assertSame(200, $this->send('GET', '/')->status, 'one hour idle is fine (120 minutes)');

        for ($i = 0; $i < 13; ++$i) {
            $this->now += 3600;
            $status = $this->send('GET', '/')->status;
        }
        self::assertSame(303, $status, 'busy for 12 hours, but the session is that old');

        $this->login();
        $this->now += 7201;
        self::assertSame(303, $this->send('GET', '/')->status, 'idle for more than two hours');
    }

    public function testAPostFromAnotherSiteIsRefusedEvenWithTheRightToken(): void
    {
        $this->installed();
        $this->login();
        $this->send('GET', '/');

        $response = $this->send('POST', '/logout', ['csrf' => $this->csrf], ['origin' => 'https://evil.example']);

        self::assertSame(403, $response->status);
        self::assertSame(200, $this->send('GET', '/')->status, 'still logged in');
    }

    // ------------------------------------------------------------------------------------------------- what every response carries

    public function testEveryResponseCarriesTheSecurityHeaders(): void
    {
        $this->installed();

        foreach ([$this->send('GET', '/login'), $this->send('GET', '/'), $this->send('GET', '/nowhere'), $this->send('PUT', '/login')] as $response) {
            $policy = (string) $response->header('Content-Security-Policy');
            self::assertStringContainsString("default-src 'none'", $policy);
            self::assertStringContainsString("script-src 'self'", $policy);
            self::assertStringContainsString("connect-src 'self'", $policy);
            self::assertStringContainsString("frame-ancestors 'none'", $policy);
            self::assertStringNotContainsString('unsafe-inline', $policy);
            self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
            self::assertSame('DENY', $response->header('X-Frame-Options'));
            self::assertSame('no-store', $response->header('Cache-Control'));
            self::assertSame('same-origin', $response->header('Referrer-Policy'));
        }
    }

    public function testNoPageHasInlineScriptOrStyleOrEventHandlers(): void
    {
        $this->installed();
        $pages = [$this->send('GET', '/login')->body];
        $this->login();
        $pages[] = $this->send('GET', '/')->body;
        $pages[] = $this->send('GET', '/nothing-here')->body;

        foreach ($pages as $html) {
            self::assertStringContainsString('<script src="/app.js" defer></script>', $html);
            self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)/i', $html, 'an inline script');
            self::assertDoesNotMatchRegularExpression('/<style/i', $html);
            self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html);
            self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html);
            self::assertDoesNotMatchRegularExpression('/href\s*=\s*"\s*javascript:/i', $html);
        }
    }

    public function testWhatAPersonTypesIsEscaped(): void
    {
        $this->installed();
        $hostile = '"><script>alert(1)</script>';

        $response = $this->post('/login', ['user' => $hostile, 'password' => 'wrong password here'], withToken: false);

        self::assertStringNotContainsString('<script>alert(1)', $response->body);
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;', $response->body);
        self::assertStringNotContainsString('wrong password here', $response->body, 'a password is never sent back');
    }

    public function testWithoutPrettyUrlsEveryLinkIsAParameterOfIndexPhp(): void
    {
        $this->installed();
        Config::defaults()->with('web.enabled', true)->with('web.prettyUrls', false)->save($this->data . '/config.json');

        $login = $this->send('POST', '/login?route=/login', ['user' => 'admin', 'password' => self::PASSWORD], base: '/ts');

        self::assertSame('/ts/index.php', $login->header('Location'));
        $dashboard = $this->send('GET', '/?route=/', base: '/ts');
        self::assertStringContainsString('action="/ts/index.php?route=/logout"', $dashboard->body);
    }

    // ------------------------------------------------------------------------------------------------- the dashboard

    public function testTheDashboardCountsTheProfilesAndShowsWhatWasDone(): void
    {
        $this->installed();
        mkdir($this->data . '/profiles', 0700, true);
        $this->login();

        $dashboard = $this->send('GET', '/');

        self::assertStringContainsString('<strong>0</strong> sites', $dashboard->body);
        self::assertStringContainsString('login', $dashboard->body, 'the login is in the recent activity');
        self::assertStringContainsString('<time datetime="2027-01-15T08:00:00Z">', $dashboard->body);
    }

    public function testTheDataDirectoryIsClosedToTheWebWhenTheInterfaceIsUsed(): void
    {
        $this->installed();
        $this->send('GET', '/login');

        self::assertFileExists($this->data . '/.htaccess');
        self::assertStringContainsString('Require all denied', (string) file_get_contents($this->data . '/.htaccess'));
        self::assertFileExists($this->data . '/index.html');
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0700, fileperms($this->data) & 0777);
        }
    }

    public function testAnErrorShowsItsMessageOnlyToALoggedInPerson(): void
    {
        $this->installed();
        // a session directory that cannot be written: a file where the directory should be
        file_put_contents($this->data . '/sessions', 'in the way');

        $anonymous = $this->post('/login', ['user' => 'admin', 'password' => self::PASSWORD], withToken: false);

        self::assertSame(500, $anonymous->status);
        self::assertStringNotContainsString($this->data, $anonymous->body, 'no path for a stranger');
        self::assertStringContainsString('Please try again later.', $anonymous->body);
    }
}
