<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ThreadSafe\Admin\AuthController;
use ThreadSafe\Admin\Kernel;
use ThreadSafe\Admin\Router;
use ThreadSafe\Admin\Services;
use ThreadSafe\Auth\Base64Url;
use ThreadSafe\Auth\CoseKey;
use ThreadSafe\Auth\PasskeyStore;
use ThreadSafe\Auth\Passkeys;
use ThreadSafe\Tests\Support\AdminBrowser;
use ThreadSafe\Tests\Support\SoftwareAuthenticator;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\Response;

/**
 * The login with a passkey (docs/PHASE14.md 3.3): the button only where it can work, a challenge in a session that is no login, one use,
 * five minutes, every check refused with one general answer and an audit line, a new session after a good answer, the page of the code, and that
 * nothing waits or counts, so that a stranger cannot shut the door for the owner.
 */
final class PasskeyLoginTest extends TestCase
{
    use TempDirectory;

    private const string HOST = 'admin.example.test';

    private AdminBrowser $browser;

    private string $data;

    protected function setUp(): void
    {
        $this->data = $this->tempDirectory() . '/data';
        $this->browser = $this->visitor();
        $this->browser->install();
        $this->browser->now = time(); // sessions are files with the real time: a clock far ahead would count them as old
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function visitor(): AdminBrowser
    {
        $browser = new AdminBrowser($this->dataDirectory());
        $browser->host = self::HOST;
        $browser->https = true;

        return $browser;
    }

    private function dataDirectory(): string
    {
        return $this->data;
    }

    private function store(): PasskeyStore
    {
        return new PasskeyStore($this->dataDirectory() . '/passkeys.json');
    }

    private function audit(): string
    {
        $file = $this->dataDirectory() . '/audit.log';

        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    /** A passkey that was registered earlier, as the page of the security would have stored it. */
    private function registered(int $algorithm = CoseKey::ES256, string $name = 'Phone'): SoftwareAuthenticator
    {
        $device = new SoftwareAuthenticator($algorithm, 'https://' . self::HOST, self::HOST);
        $rp = new Passkeys(self::HOST, 'https://' . self::HOST);
        $store = $this->store();
        $handle = $store->userHandle();
        $challenge = Passkeys::newChallenge();
        $credential = $rp->verifyRegistration($challenge, $device->register($rp->registrationOptions($challenge, $handle, 'admin', array_map(static fn ($c): string => $c->id, $store->credentials()))), []);
        $store->add('admin', self::HOST, $handle, $credential, $name);

        return $device;
    }

    /** @return array<string, mixed> */
    private function options(AdminBrowser $browser): array
    {
        $answer = $browser->send('POST', '/login/passkey/options');
        self::assertSame(200, $answer->status, $answer->body);
        $options = json_decode($answer->body, true)['options'];
        self::assertIsArray($options);

        return $options;
    }

    /** @param array<string, mixed> $spoil */
    private function login(AdminBrowser $browser, SoftwareAuthenticator $device, array $spoil = []): Response
    {
        return $browser->send('POST', '/login/passkey', [], ['content-type' => 'application/json'], '127.0.0.1', json_encode($device->login($this->options($browser), $spoil), JSON_THROW_ON_ERROR));
    }

    public function testTheButtonIsOnTheLoginPageOnlyWhereAPasskeyCanWork(): void
    {
        self::assertStringNotContainsString('passkey-login', $this->browser->get('/login')->body, 'no passkey, no button');

        $this->registered();
        $page = $this->browser->get('/login')->body;
        self::assertStringContainsString('id="passkey-login"', $page);
        self::assertStringContainsString('data-login-url="/login/passkey"', $page);
        self::assertStringContainsString('Log in with a passkey', $page);
        self::assertStringContainsString('name="password"', $page, 'the password form stays');

        $other = $this->visitor();
        $other->host = 'other.example.test';
        self::assertStringNotContainsString('passkey-login', $other->get('/login')->body, 'another host name');

        $plain = $this->visitor();
        $plain->https = false;
        self::assertStringNotContainsString('passkey-login', $plain->get('/login')->body, 'plain http');
    }

    #[DataProvider('algorithms')]
    public function testAPasskeyLogsInWithANewSessionAndTheCounterIsWritten(int $algorithm): void
    {
        $device = $this->registered($algorithm);
        $options = $this->options($this->browser);
        $challengeSession = $this->browser->cookie;
        self::assertNotSame('', $challengeSession, 'the challenge waits in a session');
        self::assertSame(32, strlen(Base64Url::decode($options['challenge'])));
        self::assertSame(self::HOST, $options['rpId']);
        self::assertSame('required', $options['userVerification']);
        self::assertArrayNotHasKey('allowCredentials', $options, 'the device offers its passkeys: the server does not say which exist');

        $answer = $this->browser->send('POST', '/login/passkey', [], ['content-type' => 'application/json'], '127.0.0.1', json_encode($device->login($options), JSON_THROW_ON_ERROR));

        self::assertSame(200, $answer->status, $answer->body);
        self::assertSame(['ok' => true, 'redirect' => '/'], json_decode($answer->body, true));
        self::assertNotSame($challengeSession, $this->browser->cookie, 'a new session is made at the login');
        self::assertSame(200, $this->browser->get('/profiles')->status, 'logged in');
        self::assertStringContainsString('"mfa":"passkey"', $this->audit());
        $stored = $this->store()->all()[0];
        self::assertSame(1, $stored->credential->signCount);
        self::assertNotNull($stored->lastUsed);
    }

    /** @return iterable<string, array{int}> */
    public static function algorithms(): iterable
    {
        yield 'ES256' => [CoseKey::ES256];
        yield 'EdDSA' => [CoseKey::EDDSA];
        yield 'RS256' => [CoseKey::RS256];
    }

    public function testTheChallengeIsUsedOnceAndAnAnswerCannotBeReplayed(): void
    {
        $device = $this->registered();
        $answer = json_encode($device->login($this->options($this->browser)), JSON_THROW_ON_ERROR);
        $send = fn () => $this->browser->send('POST', '/login/passkey', [], ['content-type' => 'application/json'], '127.0.0.1', $answer);

        self::assertSame(200, $send()->status);
        $this->browser->cookie = '';
        self::assertSame(409, $send()->status, 'without a challenge of its own session the answer is nothing');
    }

    public function testTheChallengeRunsOutAfterFiveMinutes(): void
    {
        $device = $this->registered();
        $answer = json_encode($device->login($this->options($this->browser)), JSON_THROW_ON_ERROR);
        $this->browser->now += 301;

        $late = $this->browser->send('POST', '/login/passkey', [], ['content-type' => 'application/json'], '127.0.0.1', $answer);

        self::assertSame(409, $late->status);
        self::assertSame(303, $this->browser->get('/profiles')->status);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function spoiledAnswers(): iterable
    {
        yield 'another origin' => [['origin' => 'https://evil.example.test']];
        yield 'another challenge' => [['challenge' => 'AAAA']];
        yield 'another relying party id' => [['rpId' => 'evil.example.test']];
        yield 'no user verification' => [['flags' => SoftwareAuthenticator::UP]];
        yield 'a registration answer' => [['type' => 'webauthn.create']];
        yield 'a damaged signature' => [['corruptSignature' => true]];
        yield 'another passkey id' => [['id' => 'AAAAAAAA']];
        yield 'another user handle' => [['userHandle' => 'AAAAAAAA']];
    }

    /** @param array<string, mixed> $spoil */
    #[DataProvider('spoiledAnswers')]
    public function testAnAnswerThatFailsACheckIsNoLoginAndTellsNoDetail(array $spoil): void
    {
        $device = $this->registered();

        $answer = $this->login($this->browser, $device, $spoil);

        self::assertSame(403, $answer->status);
        self::assertSame(['error' => 'The passkey was not accepted.'], json_decode($answer->body, true));
        self::assertSame(303, $this->browser->get('/profiles')->status, 'no login');
        self::assertStringContainsString('login.passkey.failed', $this->audit());
        self::assertStringNotContainsString('"event":"login"', $this->audit());
    }

    public function testASignatureOfAnotherDeviceIsNoLogin(): void
    {
        $device = $this->registered();
        $thief = new SoftwareAuthenticator(CoseKey::ES256, 'https://' . self::HOST, self::HOST);
        $thief->credentialId = $device->credentialId;
        $thief->userHandle = $device->userHandle;

        self::assertSame(403, $this->login($this->browser, $thief)->status);
    }

    public function testACounterThatDoesNotGoUpIsACloneAndIsAuditedAsSuch(): void
    {
        $device = $this->registered();
        self::assertSame(200, $this->login($this->browser, $device)->status);
        $again = $this->visitor();

        $answer = $this->login($again, $device, ['counter' => 1]);

        self::assertSame(403, $answer->status);
        self::assertStringContainsString('login.passkey.clone', $this->audit());
        self::assertSame(303, $again->get('/profiles')->status);
    }

    public function testNothingWaitsAndNothingCountsSoAStrangerCannotShutTheDoor(): void
    {
        $device = $this->registered();
        for ($i = 0; $i < 12; ++$i) {
            $stranger = $this->visitor();
            self::assertSame(403, $this->login($stranger, $device, ['corruptSignature' => true])->status);
        }

        self::assertSame(200, $this->login($this->visitor(), $device)->status, 'the owner is let in at once');
        self::assertFileDoesNotExist($this->dataDirectory() . '/throttle/login.json', 'the throttle of the password is untouched');
    }

    public function testAnswersWithoutAChallengeOrWithJunkAreRefused(): void
    {
        $this->registered();

        self::assertSame(409, $this->browser->send('POST', '/login/passkey', [], [], '127.0.0.1', '{}')->status, 'no session');
        $this->options($this->browser);
        self::assertSame(403, $this->browser->send('POST', '/login/passkey', [], [], '127.0.0.1', '{ not json')->status);
        $this->options($this->browser);
        self::assertSame(403, $this->browser->send('POST', '/login/passkey', [], [], '127.0.0.1', '[]')->status);
    }

    public function testNoPasskeysNoChallenge(): void
    {
        $answer = $this->browser->send('POST', '/login/passkey/options');

        self::assertSame(409, $answer->status);
        self::assertSame('', $this->browser->cookie, 'no session is made for it');
    }

    public function testALoggedInVisitorGetsNoChallenge(): void
    {
        $this->registered();
        $this->browser->login();

        self::assertSame(409, $this->browser->send('POST', '/login/passkey/options')->status);
    }

    public function testADamagedFileTakesTheButtonAwayAndNeverThePasswordLogin(): void
    {
        $this->registered();
        file_put_contents($this->dataDirectory() . '/passkeys.json', '{ damaged');

        self::assertStringNotContainsString('passkey-login', $this->browser->get('/login')->body);
        self::assertSame(409, $this->browser->send('POST', '/login/passkey/options')->status);
        self::assertSame(303, $this->browser->login()->status);
        self::assertSame(200, $this->browser->get('/profiles')->status);
    }

    public function testASessionThatHoldsAChallengeOpensNothingBehindTheLogin(): void
    {
        $this->registered();
        $this->options($this->browser);
        self::assertNotSame('', $this->browser->cookie);

        $services = new Services($this->dataDirectory());
        $kernel = new Kernel($this->dataDirectory());
        $router = (new ReflectionMethod($kernel, 'router'))->invoke($kernel, $services, new AuthController($services));
        self::assertInstanceOf(Router::class, $router);

        self::assertGreaterThan(40, TwoFactorLoginTest::walkRoutesBehindTheLogin($this->browser, $router->routes()));
    }

    public function testThePageOfTheCodeOffersAPasskeyAndAGoodAnswerEndsTheWait(): void
    {
        $device = $this->registered();
        $this->browser->enableTwoFactor();
        $this->browser->get('/login');
        self::assertSame('/login/code', $this->browser->send('POST', '/login', ['user' => 'admin', 'password' => AdminBrowser::PASSWORD])->header('Location'));
        $waiting = $this->browser->cookie;
        self::assertStringContainsString('Use a passkey instead of the code', $this->browser->get('/login/code')->body);

        $answer = $this->login($this->browser, $device);

        self::assertSame(200, $answer->status, $answer->body);
        self::assertSame(200, $this->browser->get('/profiles')->status);
        self::assertNotSame($waiting, $this->browser->cookie, 'the waiting session is gone');
    }

    public function testAChallengeAskedForOnThePageOfTheCodeKeepsTheWait(): void
    {
        $this->registered();
        $this->browser->enableTwoFactor();
        $this->browser->get('/login');
        $this->browser->send('POST', '/login', ['user' => 'admin', 'password' => AdminBrowser::PASSWORD]);
        $waiting = $this->browser->cookie;

        $this->options($this->browser);

        self::assertSame($waiting, $this->browser->cookie, 'the same session: the wait for the code is still there');
        self::assertSame(200, $this->browser->get('/login/code')->status);
        self::assertSame(303, $this->browser->get('/profiles')->status);
    }
}
