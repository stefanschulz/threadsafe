<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Auth\Base64Url;
use ThreadSafe\Auth\CoseKey;
use ThreadSafe\Auth\PasskeyStore;
use ThreadSafe\Tests\Support\AdminBrowser;
use ThreadSafe\Tests\Support\BackupFixture;
use ThreadSafe\Tests\Support\SoftwareAuthenticator;
use ThreadSafe\Tests\Support\TempDirectory;
use ThreadSafe\Web\Response;

/**
 * The section *Passkeys* of the page Security (docs/PHASE14.md 3.4): where it is offered, the password asked again, the challenge that is used
 * once, the answer of a device that is checked, and the list with rename and remove. The device is a software one that answers like a browser.
 */
final class SecurityPasskeysTest extends TestCase
{
    use TempDirectory;

    private const string HOST = 'admin.example.test';

    private AdminBrowser $browser;

    protected function setUp(): void
    {
        $fixture = new BackupFixture($this->tempDirectory());
        $this->browser = new AdminBrowser($fixture->data);
        $this->browser->host = self::HOST;
        $this->browser->https = true;
        $this->browser->install();
        $this->browser->login();
    }

    protected function tearDown(): void
    {
        $this->cleanUpTempDirectories();
    }

    private function store(): PasskeyStore
    {
        return new PasskeyStore($this->browser->data . '/passkeys.json');
    }

    private function audit(): string
    {
        $file = $this->browser->data . '/audit.log';

        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    private function device(int $algorithm = CoseKey::ES256): SoftwareAuthenticator
    {
        return new SoftwareAuthenticator($algorithm, 'https://' . self::HOST, self::HOST);
    }

    /** @return array<string, mixed> the options of the server */
    private function options(string $name = 'My phone', string $password = AdminBrowser::PASSWORD): array
    {
        $this->browser->get('/security');
        $answer = $this->browser->post('/security/passkey/options', ['passkey_name' => $name, 'passkey_password' => $password]);
        self::assertSame(200, $answer->status, $answer->body);
        $options = json_decode($answer->body, true)['options'];
        self::assertIsArray($options);

        return $options;
    }

    /**
     * @param array<string, mixed> $spoil
     */
    private function register(SoftwareAuthenticator $device, array $spoil = [], string $name = 'My phone'): Response
    {
        return $this->browser->postJson('/security/passkey/register', $device->register($this->options($name), $spoil));
    }

    public function testThePageOffersTheFormOnASecureHostWithAName(): void
    {
        $page = $this->browser->get('/security')->body;

        self::assertStringContainsString('<h2>Passkeys</h2>', $page);
        self::assertStringContainsString('No passkey yet.', $page);
        self::assertStringContainsString('id="passkey-add"', $page);
        self::assertStringContainsString('data-register-url="/security/passkey/register"', $page);
        self::assertStringContainsString('class="needs-js"', $page);
    }

    /** @return iterable<string, array{string, bool, string}> host, https, a part of the reason */
    public static function unusablePages(): iterable
    {
        yield 'plain http on a host name' => ['ts.example', false, 'secure page'];
        yield 'an IP address' => ['192.0.2.7', true, 'IP address'];
        yield 'an IP address with a port' => ['10.1.2.3:8443', true, 'IP address'];
        yield 'an IPv6 address' => ['[2001:db8::1]', true, 'host name'];
        yield 'a name with a space' => ['bad host', true, 'host name'];
        yield 'no host' => ['', true, 'host name'];
    }

    #[DataProvider('unusablePages')]
    public function testWhereAPasskeyCannotBeBoundThePageSaysWhyAndOffersNoForm(string $host, bool $https, string $reason): void
    {
        $this->browser->host = $host;
        $this->browser->https = $https;
        $page = $this->browser->get('/security');

        self::assertSame(200, $page->status);
        self::assertStringContainsString('Passkeys cannot be added here', $page->body);
        self::assertStringContainsString($reason, $page->body);
        self::assertStringNotContainsString('id="passkey-add"', $page->body);

        $answer = $this->browser->post('/security/passkey/options', ['passkey_password' => AdminBrowser::PASSWORD]);
        self::assertSame(409, $answer->status);
    }

    public function testLocalhostIsEnoughOnPlainHttp(): void
    {
        $this->browser->host = 'localhost:8080';
        $this->browser->https = false;

        self::assertStringContainsString('id="passkey-add"', $this->browser->get('/security')->body);
    }

    public function testTheOptionsNeedThePasswordAndMakeNoChallengeWithoutIt(): void
    {
        $this->browser->get('/security');
        $statuses = [];
        for ($i = 0; $i < 4; ++$i) {
            $answer = $this->browser->post('/security/passkey/options', ['passkey_password' => 'not the password at all']);
            $statuses[] = $answer->status;
        }

        self::assertSame([403, 403, 403, 429], $statuses);
        self::assertStringContainsString('"error"', $answer->body);
        self::assertStringNotContainsString('challenge', $answer->body);
    }

    public function testTheOptionsAskForAFindableVerifiedPasskeyOfThisSite(): void
    {
        $options = $this->options();

        self::assertSame(self::HOST, $options['rp']['id']);
        self::assertSame('required', $options['authenticatorSelection']['userVerification']);
        self::assertSame('required', $options['authenticatorSelection']['residentKey']);
        self::assertSame('none', $options['attestation']);
        self::assertSame(32, strlen(Base64Url::decode($options['challenge'])));
        self::assertSame([], $options['excludeCredentials']);
    }

    public function testARegistrationIsStoredListedAndAuditedAndEndsTheOtherLogins(): void
    {
        $this->browser->now = time(); // a second login in the directory: the files have the real time, and a clock far ahead would count them as old
        $other = new AdminBrowser($this->browser->data);
        $other->now = $this->browser->now;
        $other->host = self::HOST;
        $other->https = true;
        $other->login();
        self::assertSame(200, $other->get('/security')->status);

        $answer = $this->register($this->device());

        self::assertSame(200, $answer->status, $answer->body);
        self::assertSame(['ok' => true, 'redirect' => '/security'], json_decode($answer->body, true));
        self::assertSame(1, $this->store()->count());
        self::assertSame(self::HOST, $this->store()->rpId());
        self::assertSame('My phone', $this->store()->all()[0]->name);
        self::assertStringContainsString('passkey.added', $this->audit());
        self::assertSame(303, $other->get('/security')->status, 'the other login has ended');

        $page = $this->browser->get('/security')->body;
        self::assertStringContainsString('The passkey is added.', $page);
        self::assertStringContainsString('<td>My phone<br>', $page);
        self::assertStringContainsString('only on this device', $page);
        self::assertStringContainsString('not used yet', $page);
    }

    public function testEveryAlgorithmCanBeRegistered(): void
    {
        foreach ([CoseKey::EDDSA, CoseKey::RS256] as $algorithm) {
            self::assertSame(200, $this->register($this->device($algorithm))->status);
        }

        self::assertSame(2, $this->store()->count());
    }

    public function testTheChallengeIsUsedOnce(): void
    {
        $device = $this->device();
        $answer = $device->register($this->options());

        self::assertSame(200, $this->browser->postJson('/security/passkey/register', $answer)->status);
        $again = $this->browser->postJson('/security/passkey/register', $answer);

        self::assertSame(409, $again->status);
        self::assertSame(1, $this->store()->count());
    }

    public function testTheChallengeRunsOutAfterFiveMinutes(): void
    {
        $answer = $this->device()->register($this->options());
        $this->browser->now += 301;

        $late = $this->browser->postJson('/security/passkey/register', $answer);

        self::assertSame(409, $late->status);
        self::assertStringContainsString('run out of time', $late->body);
        self::assertSame(0, $this->store()->count());
    }

    public function testARegistrationWithoutOptionsIsRefused(): void
    {
        $this->browser->get('/security');

        self::assertSame(409, $this->browser->postJson('/security/passkey/register', ['clientDataJSON' => 'AAAA', 'attestationObject' => 'AAAA'])->status);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function spoiledAnswers(): iterable
    {
        yield 'another origin' => [['origin' => 'https://evil.example.test']];
        yield 'another challenge' => [['challenge' => 'AAAA']];
        yield 'another relying party id' => [['rpId' => 'evil.example.test']];
        yield 'no user verification' => [['flags' => SoftwareAuthenticator::UP | SoftwareAuthenticator::AT]];
        yield 'a login answer' => [['type' => 'webauthn.get']];
    }

    /** @param array<string, mixed> $spoil */
    #[DataProvider('spoiledAnswers')]
    public function testAnAnswerThatFailsACheckStoresNothingAndTellsNoDetail(array $spoil): void
    {
        $answer = $this->register($this->device(), $spoil);

        self::assertSame(400, $answer->status);
        self::assertSame(['error' => 'The passkey could not be registered.'], json_decode($answer->body, true));
        self::assertSame(0, $this->store()->count());
        self::assertFileDoesNotExist($this->browser->data . '/passkeys.json');
        self::assertStringContainsString('passkey.register.failed', $this->audit());
    }

    public function testTheAnswerOfABrowserThatCannotBeReadIsRefused(): void
    {
        $this->options();
        $answer = $this->browser->send('POST', '/security/passkey/register', [], ['x-csrf-token' => $this->browser->csrf], '127.0.0.1', '{ not json');

        self::assertSame(400, $answer->status);
    }

    public function testTheSameDeviceIsNotRegisteredTwice(): void
    {
        $device = $this->device();
        self::assertSame(200, $this->register($device)->status);
        $options = $this->options('Again');

        self::assertCount(1, $options['excludeCredentials'], 'the browser is told which passkeys exist');
        self::assertSame(400, $this->browser->postJson('/security/passkey/register', $device->register($options))->status);
        self::assertSame(1, $this->store()->count());
    }

    public function testARequestWithoutTheTokenOfTheSessionIsRefused(): void
    {
        $this->browser->get('/security');

        $answer = $this->browser->send('POST', '/security/passkey/options', ['passkey_password' => AdminBrowser::PASSWORD]);

        self::assertSame(403, $answer->status);
        $json = $this->browser->send('POST', '/security/passkey/register', [], ['x-csrf-token' => 'wrong'], '127.0.0.1', '{}');
        self::assertSame(403, $json->status);
    }

    public function testNoMoreThanTenPasskeysAreOffered(): void
    {
        for ($i = 0; $i < PasskeyStore::MAX_PASSKEYS; ++$i) {
            self::assertSame(200, $this->register($this->device(), [], 'Key ' . $i)->status);
        }

        $page = $this->browser->get('/security')->body;
        self::assertStringContainsString('the most there can be', $page);
        self::assertStringNotContainsString('id="passkey-add"', $page);
        self::assertSame(409, $this->browser->post('/security/passkey/options', ['passkey_password' => AdminBrowser::PASSWORD])->status);
    }

    public function testPasskeysOfAnotherHostNameAreNotMixedWithThisOne(): void
    {
        self::assertSame(200, $this->register($this->device())->status);
        $this->browser->host = 'other.example.test';

        $page = $this->browser->get('/security')->body;
        self::assertStringContainsString('bound to the host name ' . self::HOST, $page);
        self::assertStringNotContainsString('id="passkey-add"', $page);
        $answer = $this->browser->post('/security/passkey/options', ['passkey_password' => AdminBrowser::PASSWORD]);
        self::assertSame(409, $answer->status);
        self::assertStringContainsString(self::HOST, $answer->body);
    }

    public function testAPasskeyIsRenamed(): void
    {
        $this->register($this->device());
        $id = Base64Url::encode($this->store()->all()[0]->credential->id);

        $answer = $this->browser->post('/security/passkey/rename', ['id' => $id, 'name' => 'Work laptop']);

        self::assertSame(303, $answer->status);
        self::assertSame('Work laptop', $this->store()->all()[0]->name);
        self::assertStringContainsString('The passkey is renamed.', $this->browser->get('/security')->body);
    }

    public function testRemovingNeedsThePasswordAndEndsTheOtherLogins(): void
    {
        $this->register($this->device());
        $id = Base64Url::encode($this->store()->all()[0]->credential->id);

        $wrong = $this->browser->post('/security/passkey/remove', ['id' => $id, 'remove_password' => 'nope nope nope']);
        self::assertSame(403, $wrong->status);
        self::assertSame(1, $this->store()->count());

        $answer = $this->browser->post('/security/passkey/remove', ['id' => $id, 'remove_password' => AdminBrowser::PASSWORD]);

        self::assertSame(303, $answer->status);
        self::assertSame(0, $this->store()->count());
        self::assertFileDoesNotExist($this->browser->data . '/passkeys.json');
        self::assertStringContainsString('passkey.removed', $this->audit());
        self::assertStringContainsString('The passkey is removed.', $this->browser->get('/security')->body);
    }

    public function testAnIdThatIsNotBase64UrlOrNotThereIsAnError(): void
    {
        $this->register($this->device());

        $this->browser->post('/security/passkey/remove', ['id' => 'AAAAAAAA', 'remove_password' => AdminBrowser::PASSWORD]);
        self::assertStringContainsString('There is no such passkey any more.', $this->browser->get('/security')->body);
        self::assertSame(500, $this->browser->post('/security/passkey/rename', ['id' => '!!', 'name' => 'x'])->status);
        self::assertSame(1, $this->store()->count());
    }

    public function testADamagedFileTakesThePasskeysAwayAndNeverThePage(): void
    {
        file_put_contents($this->browser->data . '/passkeys.json', '{ damaged');

        $page = $this->browser->get('/security');

        self::assertSame(200, $page->status);
        self::assertStringContainsString('The password login is not affected.', $page->body);
        self::assertStringContainsString('admin passkey remove-all', $page->body);
        self::assertStringNotContainsString('id="passkey-add"', $page->body);
        self::assertSame(200, $this->browser->get('/profiles')->status, 'the rest of the interface works');
    }
}
