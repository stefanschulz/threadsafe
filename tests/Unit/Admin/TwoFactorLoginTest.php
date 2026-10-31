<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ThreadSafe\Admin\AuthController;
use ThreadSafe\Admin\Csrf;
use ThreadSafe\Admin\Kernel;
use ThreadSafe\Admin\Router;
use ThreadSafe\Admin\Services;
use ThreadSafe\Tests\Support\AdminBrowser;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\Request;

/**
 * The login in two steps (docs/PHASE11.md 3.3): the password alone opens nothing when a second factor is on, the code ends the
 * wait, a wrong, a replayed and a late code do not, and a damaged file of the factor refuses the login instead of opening it.
 */
final class TwoFactorLoginTest extends TestCase
{
    use TempDirectory;

    private AdminBrowser $browser;

    protected function setUp(): void
    {
        $fixture = new BackupFixture($this->tempDirectory());
        $this->browser = new AdminBrowser($fixture->data);
        $this->browser->install();
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    /** The first step: the right password with the factor on. */
    private function password(): \ThreadSafe\Web\Response
    {
        $this->browser->get('/login');

        return $this->browser->send('POST', '/login', ['user' => 'admin', 'password' => AdminBrowser::PASSWORD]);
    }

    private function code(string $code, bool $recovery = false): \ThreadSafe\Web\Response
    {
        return $this->browser->send('POST', '/login/code', ['code' => $code] + ($recovery ? ['recovery' => '1'] : []));
    }

    private function audit(): string
    {
        $file = $this->browser->data . '/audit.log';

        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    public function testWithoutTheFactorTheLoginIsAsBefore(): void
    {
        $answer = $this->password();

        self::assertSame(303, $answer->status);
        self::assertSame('/', $answer->header('Location'));
        self::assertSame(200, $this->browser->get('/')->status);
    }

    public function testTheRightPasswordAloneOpensNothingWhenTheFactorIsOn(): void
    {
        $this->browser->enableTwoFactor();

        $answer = $this->password();

        self::assertSame(303, $answer->status);
        self::assertSame('/login/code', $answer->header('Location'));
        self::assertNotSame('', $this->browser->cookie, 'a session cookie is set: the half of a login');
        foreach (['/', '/profiles', '/system', '/schedule', '/about'] as $route) {
            $page = $this->browser->get($route);
            self::assertSame(303, $page->status, $route);
            self::assertSame('/login', $page->header('Location'), $route);
        }
        self::assertStringNotContainsString('"event":"login"', $this->audit(), 'no login is recorded before the code');
    }

    public function testNoRouteBehindTheLoginAnswersASessionThatWaitsForTheCode(): void
    {
        $this->browser->enableTwoFactor();
        $this->password();

        $walked = 0;
        foreach ($this->routes() as $route) {
            if ($route['public']) {
                continue;
            }
            $path = (string) preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => match ($m[1]) {
                'id' => 'shop',
                'name' => 'backup.zip',
                'hex' => str_repeat('a', 32),
                'run' => 'backup-20270101-000000-abcdef01',
                default => 'x',
            }, $route['pattern']);

            $page = $this->browser->send($route['method'], $path);
            self::assertSame(303, $page->status, $route['method'] . ' ' . $path);
            self::assertSame('/login', $page->header('Location'), $route['method'] . ' ' . $path);

            $script = $this->browser->send($route['method'], $path, [], [Csrf::HEADER => 'x']);
            self::assertSame(401, $script->status, $route['method'] . ' ' . $path . ' as a call of the script');
            ++$walked;
        }
        self::assertGreaterThan(40, $walked, 'the table was walked');
    }

    public function testTheOnlyPublicRoutesAreTheLoginAndItsSecondStep(): void
    {
        $public = [];
        foreach ($this->routes() as $route) {
            if ($route['public']) {
                $public[] = $route['method'] . ' ' . $route['pattern'];
            }
        }
        sort($public);

        self::assertSame(['GET /login', 'GET /login/code', 'POST /login', 'POST /login/code'], $public, 'a new public route must be thought through against a session that waits for a code');
    }

    public function testTheRightCodeLogsInWithANewSessionAndTheWaitingOneIsGone(): void
    {
        $this->browser->enableTwoFactor();
        $this->password();
        $waiting = $this->browser->cookie;

        $answer = $this->code($this->browser->code());

        self::assertSame(303, $answer->status);
        self::assertSame('/', $answer->header('Location'));
        self::assertNotSame($waiting, $this->browser->cookie);
        self::assertSame(200, $this->browser->get('/profiles')->status);
        $full = $this->browser->cookie;
        $this->browser->cookie = $waiting;
        self::assertSame(303, $this->browser->get('/profiles')->status, 'the session of the first step opens nothing');
        $this->browser->cookie = $full;
        self::assertStringContainsString('"event":"login"', $this->audit());
        self::assertStringContainsString('"mfa":"totp"', $this->audit());
    }

    public function testNeitherTheCodeNorTheSecretIsWrittenToTheLog(): void
    {
        $this->browser->enableTwoFactor();
        $this->password();
        $code = $this->browser->code();
        $this->code('000000');
        $this->code($code);

        self::assertStringNotContainsString($code, $this->audit());
        self::assertStringNotContainsString('000000', $this->audit());
        self::assertStringNotContainsString(AdminBrowser::SECRET, $this->audit());
        self::assertStringContainsString('login.code.failed', $this->audit());
    }

    public function testAWrongCodeIsRefusedAndSaysWhatTimeTheServerHas(): void
    {
        $this->browser->enableTwoFactor();
        $this->password();

        $answer = $this->code('000000');

        self::assertSame(403, $answer->status);
        self::assertStringContainsString('The code is wrong.', $answer->body);
        self::assertStringContainsString(gmdate('Y-m-d H:i:s', $this->browser->now) . ' UTC', $answer->body);
        self::assertSame(303, $this->browser->get('/')->status);
        self::assertSame(403, $this->code('abc')->status, 'garbage is a wrong code like any other');
    }

    public function testTheCodeStepHasTheFieldsForAPasswordManagerAndAPhone(): void
    {
        $this->browser->enableTwoFactor();
        $this->password();

        $page = $this->browser->get('/login/code')->body;

        self::assertStringContainsString('autocomplete="one-time-code"', $page);
        self::assertStringContainsString('inputmode="numeric"', $page);
        self::assertStringContainsString('autofocus', $page);
        self::assertStringContainsString('Lost the phone?', $page);

        $recovery = $this->browser->get('/login/code?recovery=1')->body;
        self::assertStringContainsString('Recovery code', $recovery);
        self::assertStringNotContainsString('inputmode="numeric"', $recovery, 'a recovery code has letters');
        self::assertStringContainsString('name="recovery" value="1"', $recovery);
    }

    public function testFiveWrongCodesEndTheWaitAndThePasswordIsAskedAgain(): void
    {
        $this->browser->enableTwoFactor();
        $this->password();

        $statuses = [];
        foreach ([0, 6, 11, 21] as $pause) {
            $this->browser->now += $pause; // the throttle of the codes makes the later tries wait
            $statuses[] = $this->code('000000')->status;
        }
        self::assertSame([403, 403, 403, 403], $statuses);

        $this->browser->now += 41;
        $last = $this->code('000000');

        self::assertSame(403, $last->status);
        self::assertStringContainsString('Too many wrong codes. Enter the password again.', $last->body);
        $this->browser->now += 600;
        $late = $this->code($this->browser->code());
        self::assertSame(303, $late->status);
        self::assertSame('/login', $late->header('Location'), 'even the right code finds no waiting login any more');
        self::assertSame(303, $this->browser->get('/')->status);
    }

    public function testTheCodesHaveAThrottleOfTheirOwnThatTheOwnerOfThePasswordDoesNotShare(): void
    {
        $this->browser->enableTwoFactor();
        $this->password();

        $statuses = [];
        for ($i = 0; $i < 4; ++$i) {
            $statuses[] = $this->code('000000')->status;
        }
        $waiting = $this->code($this->browser->code());

        self::assertSame([403, 403, 403, 429], $statuses);
        self::assertSame(429, $waiting->status, 'during the wait even the right code is not compared');
        self::assertNotNull($waiting->header('Retry-After'));
        // the password has its own count: a new login is not slowed down by the wrong codes
        self::assertSame(303, $this->password()->status);
        $this->browser->now += 6;
        self::assertSame(303, $this->code($this->browser->code())->status);
        self::assertSame(200, $this->browser->get('/')->status);
    }

    public function testACodeAfterFiveMinutesFindsNoWaitingLogin(): void
    {
        $this->browser->enableTwoFactor();
        $this->password();
        $this->browser->now += 301;

        $answer = $this->code($this->browser->code());

        self::assertSame(403, $answer->status);
        self::assertStringContainsString('The time for the code is up.', $answer->body);
        self::assertSame(303, $this->browser->get('/')->status);
        self::assertSame(303, $this->code($this->browser->code())->status, 'and the session is gone');
    }

    public function testACodeCannotBeUsedTwiceNotEvenForANewLogin(): void
    {
        $this->browser->enableTwoFactor();
        $this->password();
        $code = $this->browser->code();
        self::assertSame(303, $this->code($code)->status);
        $this->browser->get('/');
        $this->browser->post('/logout');

        $this->password();
        $again = $this->code($code);

        self::assertSame(403, $again->status, 'the same code a second time');
        $this->browser->now += 30;
        self::assertSame(303, $this->code($this->browser->code())->status, 'the next one is fine');
        self::assertSame(200, $this->browser->get('/')->status);
    }

    public function testARecoveryCodeLogsInOnceAndTheAuditSaysHowManyAreLeft(): void
    {
        $codes = $this->browser->enableTwoFactor();
        $this->password();

        $answer = $this->code(strtolower($codes[2]), recovery: true);

        self::assertSame(303, $answer->status);
        self::assertSame('/', $answer->header('Location'));
        self::assertSame(200, $this->browser->get('/')->status);
        self::assertStringContainsString('"event":"login.recovery"', $this->audit());
        self::assertStringContainsString('"left":9', $this->audit());
        self::assertStringContainsString('"mfa":"recovery"', $this->audit());
        self::assertStringNotContainsString($codes[2], $this->audit());

        $this->browser->post('/logout');
        $this->password();
        self::assertSame(403, $this->code($codes[2], recovery: true)->status, 'the second time');
        self::assertSame(303, $this->code($codes[3], recovery: true)->status, 'another one');
    }

    public function testADamagedFileOfTheFactorRefusesTheLoginInsteadOfOpeningIt(): void
    {
        file_put_contents($this->browser->data . '/two-factor.json', '{ damaged');

        $this->browser->get('/login');
        $answer = $this->browser->send('POST', '/login', ['user' => 'admin', 'password' => AdminBrowser::PASSWORD]);

        self::assertSame(500, $answer->status);
        self::assertStringContainsString('the login is refused', $answer->body);
        self::assertStringContainsString('admin two-factor --disable', $answer->body);
        self::assertSame([], $answer->headers('Set-Cookie'), 'no session of any kind');
        self::assertSame(303, $this->browser->get('/')->status);
        self::assertStringContainsString('login.refused', $this->audit());
    }

    public function testAWrongPasswordIsStillJustAWrongPasswordWhenTheFactorIsOn(): void
    {
        $this->browser->enableTwoFactor();
        $this->browser->get('/login');

        $answer = $this->browser->send('POST', '/login', ['user' => 'admin', 'password' => 'not the password at all']);

        self::assertSame(403, $answer->status);
        self::assertStringContainsString('The user name or the password is wrong.', $answer->body);
        self::assertStringNotContainsString('second', $answer->body);
    }

    public function testThePageOfTheCodeIsOnlyForAWaitingLogin(): void
    {
        $this->browser->enableTwoFactor();

        self::assertSame('/login', $this->browser->get('/login/code')->header('Location'), 'nobody is waiting');
        self::assertSame('/login', $this->browser->send('POST', '/login/code', ['code' => '123456'])->header('Location'));

        $this->password();
        $this->code($this->browser->code());
        self::assertSame('/', $this->browser->get('/login/code')->header('Location'), 'a full login has no use for it');
    }

    public function testTheErrorPagesShowTheirMessageOnlyToAFullLogin(): void
    {
        $this->browser->enableTwoFactor();
        $kernel = new Kernel($this->browser->data, '', fn (): int => $this->browser->now);
        $loggedIn = new ReflectionMethod($kernel, 'loggedIn');
        $request = static fn (string $cookie): Request => new Request('GET', [], [], ['threadsafe_session' => $cookie], false, '127.0.0.1', '/', ['host' => 'ts.example']);

        $this->password();
        self::assertFalse($loggedIn->invoke($kernel, $request($this->browser->cookie)), 'a session that waits for the code is not a login');

        $this->code($this->browser->code());
        self::assertTrue($loggedIn->invoke($kernel, $request($this->browser->cookie)));
    }

    /**
     * @return list<array{method: string, pattern: string, public: bool}>
     */
    private function routes(): array
    {
        $services = new Services($this->browser->data);
        $kernel = new Kernel($this->browser->data);
        $router = new ReflectionMethod($kernel, 'router');
        $table = $router->invoke($kernel, $services, new AuthController($services));
        self::assertInstanceOf(Router::class, $table);

        return $table->routes();
    }
}
