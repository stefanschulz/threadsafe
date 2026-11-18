<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Admin\Config;
use ThreadSafe\Admin\ConfigException;
use ThreadSafe\Admin\PasskeyHost;
use ThreadSafe\Auth\CoseKey;
use ThreadSafe\Auth\PasskeyException;
use ThreadSafe\Auth\PasskeyStore;
use ThreadSafe\Auth\Passkeys;
use ThreadSafe\Tests\Support\AdminBrowser;
use ThreadSafe\Tests\Support\SoftwareAuthenticator;
use ThreadSafe\Tests\Support\TempDirectory;

/**
 * Work package 6 (docs/PHASE14.md 3.5 and 3.1): `web.requireTwoFactor` counts a passkey, the System page says how things stand, and
 * `web.passkeyOrigin` binds the passkeys to an address that the page cannot see (a proxy that ends https).
 */
final class PasskeyRequirementTest extends TestCase
{
    use TempDirectory;

    private const string HOST = 'admin.example.test';

    private AdminBrowser $browser;

    private string $data;

    protected function setUp(): void
    {
        $this->data = $this->tempDirectory() . '/data';
        $this->browser = new AdminBrowser($this->data);
        $this->browser->host = self::HOST;
        $this->browser->https = true;
        $this->browser->install();
        $this->browser->now = time();
        $this->device = $this->device();
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function config(string $key, bool|string $value): void
    {
        Config::load($this->data . '/config.json')->with($key, $value)->save($this->data . '/config.json');
    }

    private SoftwareAuthenticator $device;

    private function device(): SoftwareAuthenticator
    {
        return new SoftwareAuthenticator(CoseKey::ES256, 'https://' . self::HOST, self::HOST);
    }

    /** A passkey stored as an earlier registration would have. */
    private function stored(SoftwareAuthenticator $device): void
    {
        $store = new PasskeyStore($this->data . '/passkeys.json');
        $rp = new Passkeys(self::HOST, 'https://' . self::HOST);
        $handle = $store->userHandle();
        $challenge = Passkeys::newChallenge();
        $credential = $rp->verifyRegistration($challenge, $device->register($rp->registrationOptions($challenge, $handle, 'admin', [])), []);
        $store->add('admin', self::HOST, $handle, $credential, 'Phone');
    }

    private function registerThroughThePage(AdminBrowser $browser, SoftwareAuthenticator $device): int
    {
        $browser->get('/security');
        $options = json_decode($browser->post('/security/passkey/options', ['passkey_password' => AdminBrowser::PASSWORD])->body, true)['options'] ?? null;
        self::assertIsArray($options);

        return $browser->postJson('/security/passkey/register', $device->register($options))->status;
    }

    public function testWithTheRequirementOnAPasswordLoginReachesOnlyTheSecurityPage(): void
    {
        $this->config('web.requireTwoFactor', true);
        $this->browser->login();

        $answer = $this->browser->get('/profiles');

        self::assertSame(303, $answer->status);
        self::assertSame('/security', $answer->header('Location'));
        self::assertSame(200, $this->browser->get('/security')->status);
    }

    public function testAPasskeyMadeInThisSessionIsTheSecondFactorThatTheRequirementAsksFor(): void
    {
        $this->config('web.requireTwoFactor', true);
        $this->browser->login();
        self::assertSame(303, $this->browser->get('/profiles')->status);

        self::assertSame(200, $this->registerThroughThePage($this->browser, $this->device()), 'the routes of the passkeys are open to a login that waits for its second factor');

        self::assertSame(200, $this->browser->get('/profiles')->status);
    }

    public function testRenamingAndRemovingStayShutUntilTheRequirementIsMet(): void
    {
        $this->config('web.requireTwoFactor', true);
        $this->browser->login();

        self::assertSame(303, $this->browser->post('/security/passkey/remove', ['id' => 'AAAAAAAA', 'remove_password' => AdminBrowser::PASSWORD])->status);
        self::assertSame('/security', $this->browser->post('/security/passkey/rename', ['id' => 'AAAAAAAA', 'name' => 'x'])->header('Location'));
    }

    public function testPasskeysThatExistDoNotMakeAPasswordAloneEnough(): void
    {
        $this->stored($this->device());
        $this->config('web.requireTwoFactor', true);
        $this->browser->login();

        self::assertSame(303, $this->browser->get('/profiles')->status, 'a password alone is one factor, however many passkeys there are');
        self::assertSame('/security', $this->browser->get('/profiles')->header('Location'));
    }

    public function testALoginWithAPasskeyMeetsTheRequirement(): void
    {
        $this->stored($this->device);
        $this->config('web.requireTwoFactor', true);

        $visitor = $this->visitorWithAPasskeyLogin();

        self::assertSame(200, $visitor->get('/profiles')->status, 'two factors in one step: nothing is in the way');
    }

    /** A browser that has logged in with the passkey that `stored()` put into the file. */
    private function visitorWithAPasskeyLogin(): AdminBrowser
    {
        $visitor = new AdminBrowser($this->data);
        $visitor->host = self::HOST;
        $visitor->https = true;
        $visitor->now = $this->browser->now;
        $options = json_decode($visitor->send('POST', '/login/passkey/options')->body, true)['options'];
        $answer = $visitor->send('POST', '/login/passkey', [], ['content-type' => 'application/json'], '127.0.0.1', json_encode($this->device->login($options), JSON_THROW_ON_ERROR));
        self::assertSame(200, $answer->status, $answer->body);

        return $visitor;
    }

    public function testTheSystemPageSaysHowThePasskeysStand(): void
    {
        $this->browser->login();
        $none = $this->browser->get('/system')->body;
        self::assertMatchesRegularExpression('~Passkeys.*?None registered\.~s', $none);
        self::assertStringContainsString('The login has only a password.', $none);

        $this->stored($this->device);
        $with = $this->browser->get('/system')->body;
        self::assertMatchesRegularExpression('~<tr class="ok">.*?Passkeys.*?1 passkey bound to ' . preg_quote(self::HOST, '~') . '~s', $with);
        self::assertMatchesRegularExpression('~<tr class="warning">.*?Second factor.*?the password alone still logs in~s', $with);

        $this->config('web.requireTwoFactor', true);
        self::assertSame(303, $this->browser->get('/system')->status, 'this session has a password only, so it is sent to the Security page');
        $visitor = $this->visitorWithAPasskeyLogin();
        self::assertMatchesRegularExpression('~<tr class="ok">.*?Second factor.*?Required for every login~s', $visitor->get('/system')->body);
    }

    public function testADamagedFileOfThePasskeysIsAWarningAndNeverAFailure(): void
    {
        $this->browser->login();
        file_put_contents($this->data . '/passkeys.json', '{ damaged');

        $page = $this->browser->get('/system');

        self::assertSame(200, $page->status);
        self::assertMatchesRegularExpression('~<tr class="warning">.*?Passkeys.*?The password login is not affected~s', $page->body);
    }

    public function testTheSettingIsSavedOnTheSystemPageAndAWrongOneIsRefused(): void
    {
        $this->browser->login();
        $form = ['idle' => '120', 'days' => '7', 'warn_mb' => '500', 'accel' => 'none', 'prefix' => '', 'audit_mb' => '5', 'pretty' => '1', 'tz' => '', 'grace' => '360', 'interval' => '15', 'cron_mode' => 'auto'];
        $this->browser->get('/system?tab=settings');

        $this->browser->post('/system/settings', $form + ['passkey_origin' => 'HTTPS://Admin.Example.Test']);
        self::assertSame('https://admin.example.test', Config::load($this->data . '/config.json')->passkeyOrigin(), 'kept in lower case');
        self::assertStringContainsString('value="https://admin.example.test"', $this->browser->get('/system?tab=settings')->body);

        $refused = $this->browser->post('/system/settings', $form + ['passkey_origin' => 'https://admin.example.test/login']);
        self::assertStringContainsString('must be empty or an origin', $refused->body);
        self::assertSame('https://admin.example.test', Config::load($this->data . '/config.json')->passkeyOrigin(), 'nothing was changed');
    }

    /** @return iterable<string, array{string}> */
    public static function badOrigins(): iterable
    {
        yield 'a path' => ['https://a.example.test/x'];
        yield 'a user' => ['https://me@a.example.test'];
        yield 'a query' => ['https://a.example.test?x=1'];
        yield 'no scheme' => ['a.example.test'];
        yield 'another scheme' => ['ftp://a.example.test'];
        yield 'a space' => ['https://a b.example.test'];
        yield 'a port that is too long' => ['https://a.example.test:123456'];
    }

    #[DataProvider('badOrigins')]
    public function testTheSettingMustBeAnOriginAndNothingElse(string $origin): void
    {
        $this->expectException(ConfigException::class);
        Config::defaults()->with('web.passkeyOrigin', $origin);
    }

    public function testBehindAProxyThatEndsHttpsTheSettingBindsThePasskeys(): void
    {
        $this->browser->https = false;
        $this->browser->host = 'ts.internal';
        $this->browser->login();
        self::assertStringContainsString('Passkeys cannot be added here', $this->browser->get('/security')->body, 'the page cannot see that the browser used https');

        $this->config('web.passkeyOrigin', 'https://' . self::HOST);
        self::assertStringContainsString('id="passkey-add"', $this->browser->get('/security')->body);

        self::assertSame(200, $this->registerThroughThePage($this->browser, $this->device()));
        self::assertSame(self::HOST, (new PasskeyStore($this->data . '/passkeys.json'))->rpId());
    }

    public function testTheLoginButtonFollowsTheSettingToo(): void
    {
        $this->stored($this->device());
        $proxy = new AdminBrowser($this->data);
        $proxy->host = 'ts.internal';
        $proxy->https = false;
        self::assertStringNotContainsString('passkey-login', $proxy->get('/login')->body);

        $this->config('web.passkeyOrigin', 'https://' . self::HOST);

        self::assertStringContainsString('passkey-login', $proxy->get('/login')->body);
    }

    /** @return iterable<string, array{string, string, string}> origin, rp id, the origin that is used */
    public static function origins(): iterable
    {
        yield 'https' => ['https://Admin.Example.Test', 'admin.example.test', 'https://admin.example.test'];
        yield 'the standard port goes' => ['https://admin.example.test:443', 'admin.example.test', 'https://admin.example.test'];
        yield 'another port stays' => ['https://admin.example.test:8443', 'admin.example.test', 'https://admin.example.test:8443'];
        yield 'localhost on http' => ['http://localhost:8080', 'localhost', 'http://localhost:8080'];
    }

    #[DataProvider('origins')]
    public function testAnOriginIsTakenAsItIs(string $given, string $rpId, string $origin): void
    {
        $host = PasskeyHost::fromOrigin($given);

        self::assertSame([$rpId, $origin], [$host->rpId, $host->origin]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unusableOrigins(): iterable
    {
        yield 'http on a host name' => ['http://admin.example.test', 'secure page'];
        yield 'an IP address' => ['https://192.0.2.7', 'IP address'];
        yield 'not an origin' => ['admin.example.test', 'not an origin'];
    }

    #[DataProvider('unusableOrigins')]
    public function testAnOriginThatCannotBeUsedIsExplained(string $given, string $reason): void
    {
        $this->expectException(PasskeyException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($reason, '/') . '/');
        PasskeyHost::fromOrigin($given);
    }
}
