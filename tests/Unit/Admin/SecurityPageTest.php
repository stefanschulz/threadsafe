<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use ThreadSafe\Auth\QrCode;
use ThreadSafe\Auth\RecoveryCodes;
use ThreadSafe\Auth\Totp;
use ThreadSafe\Auth\TwoFactorStore;
use ThreadSafe\Tests\Support\AdminBrowser;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\Response;

/**
 * The page Security (docs/PHASE11.md 3.4): switching on in three steps, the recovery codes that are shown once, new codes, switching off,
 * the password asked again, and that every other login ends when the factor is switched on or off.
 */
final class SecurityPageTest extends TestCase
{
    use TempDirectory;

    private AdminBrowser $browser;

    protected function setUp(): void
    {
        $fixture = new BackupFixture($this->tempDirectory());
        $this->browser = new AdminBrowser($fixture->data);
        $this->browser->install();
        $this->browser->login();
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function store(): TwoFactorStore
    {
        return new TwoFactorStore($this->browser->data . '/two-factor.json', fn (): int => $this->browser->now);
    }

    private function audit(): string
    {
        $file = $this->browser->data . '/audit.log';

        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    /**
     * Steps 1 and 2 through the pages.
     *
     * @return array{secret: string, codes: list<string>, answer: Response}
     */
    private function switchOn(): array
    {
        $this->browser->get('/security');
        self::assertSame(303, $this->browser->post('/security/start', ['password' => AdminBrowser::PASSWORD])->status);
        $page = $this->browser->get('/security')->body;
        self::assertSame(1, preg_match('~<code id="totp-key" class="secret">([A-Z2-7 ]+)</code>~', $page, $m), 'the key is on the page');
        $secret = str_replace(' ', '', $m[1] ?? '');
        $answer = $this->browser->post('/security/confirm', ['code' => Totp::code($secret, Totp::step($this->browser->now))]);

        return ['secret' => $secret, 'codes' => $this->shownCodes($answer), 'answer' => $answer];
    }

    /** @return list<string> */
    private function shownCodes(Response $answer): array
    {
        if (preg_match('~<pre id="recovery-codes" class="secret"><code>(.*?)</code></pre>~s', $answer->body, $m) !== 1) {
            return [];
        }

        return array_values(array_filter(explode("\n", trim($m[1]))));
    }

    public function testTheMenuHasTheEntryAndTheOffPageAsksForThePassword(): void
    {
        $page = $this->browser->get('/security');

        self::assertSame(200, $page->status);
        self::assertStringContainsString('<a href="/security" title="Security" aria-current="page">', $page->body);
        self::assertStringContainsString('Set up the second factor', $page->body);
        self::assertStringContainsString('name="password"', $page->body);
        self::assertStringNotContainsString('totp-key', $page->body, 'no secret before the password was given');
        self::assertStringContainsString('<a href="/security" title="Security">', $this->browser->get('/profiles')->body);
    }

    public function testAWrongPasswordMakesNoSecretAndCountsInTheThrottleOfTheLogin(): void
    {
        $this->browser->get('/security');

        $statuses = [];
        for ($i = 0; $i < 4; ++$i) {
            $answer = $this->browser->post('/security/start', ['password' => 'not the password at all']);
            $statuses[] = $answer->status;
        }

        self::assertSame([403, 403, 403, 429], $statuses);
        self::assertStringContainsString('Too many wrong attempts', $answer->body);
        self::assertStringNotContainsString('totp-key', $this->browser->get('/security')->body);
        self::assertFileDoesNotExist($this->browser->data . '/two-factor.json');
        self::assertStringContainsString('security.password.failed', $this->audit());
    }

    public function testTheSecretWaitsInTheSessionUntilACodeProvesIt(): void
    {
        $this->browser->get('/security');
        $this->browser->post('/security/start', ['password' => AdminBrowser::PASSWORD]);

        $page = $this->browser->get('/security')->body;

        self::assertStringContainsString('Add the key to your app', $page);
        self::assertSame(1, preg_match('~<code id="totp-key" class="secret">([A-Z2-7]{4}( [A-Z2-7]{4}){7})</code>~', $page), 'the key in 8 groups of 4');
        self::assertStringContainsString('href="otpauth://totp/ThreadSafe%20%28ts.example%29:admin?secret=', $page, 'the link for the phone');
        self::assertStringContainsString('autocomplete="off"', $page);
        self::assertFileDoesNotExist($this->browser->data . '/two-factor.json', 'nothing is kept before the code is right');
        self::assertFalse($this->store()->isOn('admin'));
    }

    public function testThePageHoldsAQrCodeOfTheAddressForTheApp(): void
    {
        $this->browser->get('/security');
        $this->browser->post('/security/start', ['password' => AdminBrowser::PASSWORD]);

        $page = $this->browser->get('/security')->body;

        self::assertSame(1, preg_match('~<code id="totp-key" class="secret">([A-Z2-7 ]+)</code>~', $page, $m));
        $secret = str_replace(' ', '', $m[1] ?? '');
        $picture = QrCode::svg(Totp::uri($secret, 'admin', 'ts.example'), 'QR code that holds the key for the authenticator app');
        self::assertStringContainsString($picture, $page, 'the picture is the code of the address that the link has');
        self::assertStringContainsString('scan this code', $page);
        self::assertStringNotContainsString($secret, $picture, 'the key is not in the picture as text');
    }

    public function testALongHostNameStillGivesAPictureAndNeverBreaksThePage(): void
    {
        $this->browser->get('/security');
        $this->browser->post('/security/start', ['password' => AdminBrowser::PASSWORD]);

        $page = $this->browser->send('GET', '/security', [], ['host' => str_repeat('a', 60) . '.example.com'])->body;

        self::assertStringContainsString('<svg class="qr"', $page);
        self::assertStringContainsString('totp-key', $page);
    }

    public function testAWrongCodeSwitchesNothingOnAndTheSameSecretStaysForANewTry(): void
    {
        $this->browser->get('/security');
        $this->browser->post('/security/start', ['password' => AdminBrowser::PASSWORD]);
        $before = $this->browser->get('/security')->body;
        preg_match('~<code id="totp-key" class="secret">([A-Z2-7 ]+)</code>~', $before, $m);

        $answer = $this->browser->post('/security/confirm', ['code' => '000000']);

        self::assertSame(403, $answer->status);
        self::assertStringContainsString('That code is not right.', $answer->body);
        self::assertStringContainsString($m[1] ?? 'no key', $answer->body, 'the same key, so the app need not be set up again');
        self::assertFalse($this->store()->isOn('admin'));
    }

    public function testTheRightCodeSwitchesItOnAndShowsTenRecoveryCodesOnce(): void
    {
        $result = $this->switchOn();

        self::assertSame(200, $result['answer']->status);
        self::assertStringContainsString('The second factor is on', $result['answer']->body);
        self::assertCount(10, $result['codes']);
        foreach ($result['codes'] as $code) {
            self::assertTrue(RecoveryCodes::looksLike($code), $code);
        }
        self::assertStringContainsString('data-copy="#recovery-codes"', $result['answer']->body);
        self::assertStringContainsString('shown once', $result['answer']->body);
        self::assertTrue($this->store()->isOn('admin'));
        self::assertSame(10, $this->store()->recoveryLeft('admin'));

        $again = $this->browser->get('/security');
        self::assertSame([], $this->shownCodes($again), 'the codes are not shown again');
        self::assertStringContainsString('The second factor is <strong>on</strong>', $again->body);
        self::assertStringContainsString('Recovery codes left: <strong>10</strong>', $again->body);
        $file = (string) file_get_contents($this->browser->data . '/two-factor.json');
        foreach ($result['codes'] as $code) {
            self::assertStringNotContainsString($code, $file);
        }
    }

    public function testNeitherTheSecretNorAnyCodeIsWrittenToTheLogOrTheSession(): void
    {
        $result = $this->switchOn();

        self::assertStringContainsString('twofactor.enabled', $this->audit());
        self::assertStringNotContainsString($result['secret'], $this->audit());
        foreach ($result['codes'] as $code) {
            self::assertStringNotContainsString($code, $this->audit());
        }
        foreach (glob($this->browser->data . '/sessions/*.json') ?: [] as $session) {
            $content = (string) file_get_contents($session);
            self::assertStringNotContainsString($result['secret'], $content, 'the session does not keep the secret once it is confirmed');
            foreach ($result['codes'] as $code) {
                self::assertStringNotContainsString($code, $content);
            }
        }
    }

    public function testTheCodeThatProvedTheSecretCannotLogInAfterwards(): void
    {
        $result = $this->switchOn();
        $code = Totp::code($result['secret'], Totp::step($this->browser->now));
        $this->browser->get('/');
        $this->browser->post('/logout');
        $this->browser->get('/login');
        $this->browser->send('POST', '/login', ['user' => 'admin', 'password' => AdminBrowser::PASSWORD]);

        self::assertSame(403, $this->browser->send('POST', '/login/code', ['code' => $code])->status, 'its step is spent');
        $this->browser->now += 30;
        self::assertSame(303, $this->browser->send('POST', '/login/code', ['code' => Totp::code($result['secret'], Totp::step($this->browser->now))])->status);
    }

    public function testSwitchingOnEndsEveryOtherLoginEvenOneThatWaitsForACode(): void
    {
        $this->browser->now = time(); // a second login in the directory: the files have the real time, and a clock far ahead would count them as old
        $other = new AdminBrowser($this->browser->data);
        $other->now = $this->browser->now;
        $other->login();
        self::assertSame(200, $other->get('/profiles')->status);
        $this->browser->get('/security');

        $this->switchOn();

        self::assertSame(303, $other->get('/profiles')->status, 'the other login is gone');
        self::assertSame(200, $this->browser->get('/profiles')->status, 'this one stays');
        $waiting = new AdminBrowser($this->browser->data);
        $waiting->now = $this->browser->now;
        $waiting->get('/login');
        self::assertSame('/login/code', $waiting->send('POST', '/login', ['user' => 'admin', 'password' => AdminBrowser::PASSWORD])->header('Location'));
        $this->browser->get('/security');
        $this->browser->post('/security/disable', ['off_password' => AdminBrowser::PASSWORD, 'off_code' => 'wrong']);
        self::assertSame(303, $waiting->get('/profiles')->status);
    }

    public function testASetupThatWasNotFinishedIsGivenUpAfterAQuarterOfAnHourOrOnCancel(): void
    {
        $this->browser->get('/security');
        $this->browser->post('/security/start', ['password' => AdminBrowser::PASSWORD]);
        $this->browser->now += 901;
        self::assertStringNotContainsString('totp-key', $this->browser->get('/security')->body, 'too old');

        $this->browser->post('/security/start', ['password' => AdminBrowser::PASSWORD]);
        self::assertStringContainsString('totp-key', $this->browser->get('/security')->body);
        $this->browser->post('/security/cancel');
        self::assertStringNotContainsString('totp-key', $this->browser->get('/security')->body);
        self::assertSame(303, $this->browser->post('/security/confirm', ['code' => '123456'])->status, 'there is nothing to confirm');
    }

    public function testNewRecoveryCodesNeedThePasswordAndACodeAndReplaceTheOldOnes(): void
    {
        $codes = $this->browser->enableTwoFactor();
        $this->browser->get('/security');

        $wrongPassword = $this->browser->post('/security/recovery', ['new_password' => 'nope nope nope', 'new_code' => $this->browser->code()]);
        self::assertSame(403, $wrongPassword->status);
        self::assertSame(10, $this->store()->recoveryLeft('admin'), 'nothing changed');
        self::assertTrue(RecoveryCodes::looksLike($codes[0]));

        $answer = $this->browser->post('/security/recovery', ['new_password' => AdminBrowser::PASSWORD, 'new_code' => $this->browser->code()]);

        self::assertSame(200, $answer->status);
        $new = $this->shownCodes($answer);
        self::assertCount(10, $new);
        self::assertFalse($this->store()->useRecoveryCode('admin', $codes[0]), 'the old codes are dead');
        self::assertTrue($this->store()->useRecoveryCode('admin', $new[0]));
        self::assertStringContainsString('twofactor.recovery.made', $this->audit());
    }

    public function testARecoveryCodeDoesInPlaceOfTheCodeOfTheApp(): void
    {
        $codes = $this->browser->enableTwoFactor();
        $this->browser->get('/security');

        $answer = $this->browser->post('/security/disable', ['off_password' => AdminBrowser::PASSWORD, 'off_code' => $codes[4]]);

        self::assertSame(303, $answer->status);
        self::assertFalse($this->store()->isOn('admin'));
    }

    public function testSwitchingOffNeedsThePasswordAndACodeAndEndsTheOtherLogins(): void
    {
        $this->browser->now = time(); // see the test above
        $this->browser->enableTwoFactor();
        $other = new AdminBrowser($this->browser->data);
        $other->now = $this->browser->now;
        $other->get('/login');
        $other->send('POST', '/login', ['user' => 'admin', 'password' => AdminBrowser::PASSWORD]);
        $other->send('POST', '/login/code', ['code' => $other->code()]);
        self::assertSame(200, $other->get('/profiles')->status);

        $this->browser->now += 30;
        $this->browser->get('/login');
        $this->browser->send('POST', '/login', ['user' => 'admin', 'password' => AdminBrowser::PASSWORD]);
        $this->browser->send('POST', '/login/code', ['code' => $this->browser->code()]);
        $this->browser->get('/security');
        $other->now = $this->browser->now;

        self::assertSame(403, $this->browser->post('/security/disable', ['off_password' => 'wrong wrong wrong', 'off_code' => $this->browser->code(1)])->status);
        self::assertSame(403, $this->browser->post('/security/disable', ['off_password' => AdminBrowser::PASSWORD, 'off_code' => '000000'])->status);
        self::assertTrue($this->store()->isOn('admin'), 'nothing was switched off by a wrong password or a wrong code');

        $answer = $this->browser->post('/security/disable', ['off_password' => AdminBrowser::PASSWORD, 'off_code' => $this->browser->code(1)]);

        self::assertSame(303, $answer->status);
        self::assertFalse($this->store()->isOn('admin'));
        self::assertFileDoesNotExist($this->browser->data . '/two-factor.json');
        self::assertStringContainsString('The second factor is off', $this->browser->get('/security')->body);
        self::assertSame(303, $other->get('/profiles')->status, 'the other login ended');
        self::assertStringContainsString('twofactor.disabled', $this->audit());
    }

    public function testWrongCodesOnThisPageHaveTheThrottleOfTheCodes(): void
    {
        $this->browser->enableTwoFactor();
        $this->browser->get('/security');

        $statuses = [];
        for ($i = 0; $i < 4; ++$i) {
            $statuses[] = $this->browser->post('/security/disable', ['off_password' => AdminBrowser::PASSWORD, 'off_code' => '000000'])->status;
        }

        self::assertSame([403, 403, 403, 429], $statuses);
        self::assertStringContainsString('security.code.failed', $this->audit());
    }

    public function testFewRecoveryCodesLeftAreWarnedAbout(): void
    {
        $codes = $this->browser->enableTwoFactor();
        foreach (array_slice($codes, 0, 8) as $code) {
            $this->store()->useRecoveryCode('admin', $code);
        }

        $page = $this->browser->get('/security')->body;

        self::assertStringContainsString('Only 2 recovery codes are left.', $page);
        self::assertSame(1, preg_match('~Recovery codes left: <strong>2</strong>~', $page));
    }

    public function testADamagedFileIsSaidOnThePageAndOffersTheWayBack(): void
    {
        file_put_contents($this->browser->data . '/two-factor.json', '{ damaged');

        $page = $this->browser->get('/security');

        self::assertSame(500, $page->status);
        self::assertStringContainsString('The login is refused until it is repaired.', $page->body);
        self::assertStringContainsString('delete the file <code>two-factor.json</code>', $page->body);
    }

    public function testTheFormsHaveFieldsWithUniqueIdsAndTheRightAutocomplete(): void
    {
        $this->browser->enableTwoFactor();
        $page = $this->browser->get('/security')->body;

        preg_match_all('/ id="([^"]+)"/', $page, $ids);
        self::assertSame(array_unique($ids[1]), $ids[1], 'no id twice on the page');
        self::assertStringContainsString('autocomplete="current-password"', $page);
        self::assertStringContainsString('If the phone is lost', $page);
    }

    public function testEveryRouteOfThePageNeedsALogin(): void
    {
        $visitor = new AdminBrowser($this->browser->data);
        foreach (['GET /security', 'POST /security/start', 'POST /security/confirm', 'POST /security/cancel', 'POST /security/recovery', 'POST /security/disable'] as $request) {
            [$method, $route] = explode(' ', $request);
            $answer = $visitor->send($method, $route);
            self::assertSame(303, $answer->status, $request);
            self::assertStringEndsWith('/login', (string) $answer->header('Location'), $request);
        }
    }
}
