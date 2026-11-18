<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Auth\Base64Url;
use ThreadSafe\Auth\CoseKey;
use ThreadSafe\Auth\PasskeyClonedException;
use ThreadSafe\Auth\PasskeyCredential;
use ThreadSafe\Auth\PasskeyException;
use ThreadSafe\Auth\Passkeys;
use ThreadSafe\Tests\Support\SoftwareAuthenticator as Device;

/**
 * The checks of a registration and a login (docs/PHASE14.md 3.2 and 4.1). Two oracles: the answers of a real browser
 * (`tests/fixtures/webauthn/chrome-virtual.json`) and a software device that can spoil each answer in one place, so that every check has a
 * test that shows it refuses what it is there for.
 */
final class PasskeysTest extends TestCase
{
    private const string RP = 'admin.example.test';
    private const string ORIGIN = 'https://admin.example.test';

    /** @return iterable<string, array{int}> */
    public static function algorithms(): iterable
    {
        yield 'ES256' => [CoseKey::ES256];
        yield 'EdDSA' => [CoseKey::EDDSA];
        yield 'RS256' => [CoseKey::RS256];
    }

    private function relyingParty(): Passkeys
    {
        return new Passkeys(self::RP, self::ORIGIN);
    }

    /** @param array<string, mixed> $spoil */
    private function register(Device $device, array $spoil = [], ?Passkeys $rp = null): PasskeyCredential
    {
        $rp ??= $this->relyingParty();
        $challenge = Passkeys::newChallenge();
        $options = $rp->registrationOptions($challenge, 'handle-of-64-bytes-' . str_repeat('x', 45), 'admin', []);

        return $rp->verifyRegistration($challenge, $device->register($options, $spoil), []);
    }

    /**
     * @param list<PasskeyCredential> $credentials
     * @param array<string, mixed>    $spoil
     */
    private function login(Device $device, array $credentials, array $spoil = []): PasskeyCredential
    {
        $rp = $this->relyingParty();
        $challenge = Passkeys::newChallenge();

        return $rp->verifyAssertion($challenge, $device->login($rp->assertionOptions($challenge), $spoil), $credentials, $device->userHandle);
    }

    private function refused(callable $call, string $message): void
    {
        try {
            $call();
            self::fail('The answer was accepted.');
        } catch (PasskeyException $e) {
            self::assertMatchesRegularExpression('/' . preg_quote($message, '/') . '/', $e->getMessage());
        }
    }

    public function testTheOptionsAskForAPasskeyThatIsVerifiedAndFindable(): void
    {
        $options = $this->relyingParty()->registrationOptions("\x01" . str_repeat("\x00", 31), 'handle', 'admin', ["\x09\x09"]);

        self::assertSame(self::RP, $options['rp']['id']);
        self::assertSame('required', $options['authenticatorSelection']['residentKey']);
        self::assertSame('required', $options['authenticatorSelection']['userVerification']);
        self::assertSame('none', $options['attestation']);
        self::assertSame(array_map(static fn (int $a): array => ['type' => 'public-key', 'alg' => $a], CoseKey::supported()), $options['pubKeyCredParams']);
        self::assertSame([['type' => 'public-key', 'id' => Base64Url::encode("\x09\x09")]], $options['excludeCredentials']);
        self::assertSame(Base64Url::encode('handle'), $options['user']['id']);

        $login = $this->relyingParty()->assertionOptions('c');
        self::assertSame(self::RP, $login['rpId']);
        self::assertSame('required', $login['userVerification']);
        self::assertArrayNotHasKey('allowCredentials', $login);
    }

    public function testChallengesAreRandom(): void
    {
        self::assertSame(32, strlen(Passkeys::newChallenge()));
        self::assertNotSame(Passkeys::newChallenge(), Passkeys::newChallenge());
    }

    #[DataProvider('algorithms')]
    public function testARegistrationAndALoginWork(int $algorithm): void
    {
        $device = new Device($algorithm, self::ORIGIN, self::RP);
        $credential = $this->register($device);

        self::assertSame($device->credentialId, $credential->id);
        self::assertSame($algorithm, $credential->key->algorithm);
        self::assertFalse($credential->backupEligible);
        self::assertSame(['internal'], $credential->transports);

        $after = $this->login($device, [$credential]);
        self::assertSame(1, $after->signCount);
        $after = $this->login($device, [$after]);
        self::assertSame(2, $after->signCount);
    }

    public function testASyncedPasskeyIsRecognisedByItsFlags(): void
    {
        $device = new Device(CoseKey::ES256, self::ORIGIN, self::RP, true);
        $credential = $this->register($device);

        self::assertTrue($credential->backupEligible);
        self::assertTrue($credential->backupState);
        self::assertTrue($this->login($device, [$credential])->backupState);
    }

    public function testAnExtraMemberOfTheClientDataIsAllowed(): void
    {
        // Windows Hello writes one (docs/PHASE14.md 8.1); the client data is never compared with a template
        $device = new Device(CoseKey::ES256, self::ORIGIN, self::RP);
        $spoil = ['extra' => ['other_keys_can_be_added_here' => 'do not compare clientDataJSON against a template']];
        $credential = $this->register($device, $spoil);

        self::assertSame(1, $this->login($device, [$credential], $spoil)->signCount);
    }

    public function testACounterThatStaysAtZeroIsAllowed(): void
    {
        // a synced passkey says 0 every time
        $device = new Device(CoseKey::ES256, self::ORIGIN, self::RP, true);
        $credential = $this->register($device);
        $after = $this->login($device, [$credential], ['counter' => 0]);
        $after = $this->login($device, [$after], ['counter' => 0]);

        self::assertSame(0, $after->signCount);
    }

    public function testACounterThatDoesNotGoUpIsACloneAndIsRefused(): void
    {
        $device = new Device(CoseKey::ES256, self::ORIGIN, self::RP);
        $credential = $this->register($device);
        $once = $this->login($device, [$credential]);

        $this->expectException(PasskeyClonedException::class);
        $this->login($device, [$once], ['counter' => 1]);
    }

    public function testACounterThatFallsBackToZeroIsAClone(): void
    {
        $device = new Device(CoseKey::ES256, self::ORIGIN, self::RP);
        $credential = $this->register($device);
        $once = $this->login($device, [$credential]);

        $this->expectException(PasskeyClonedException::class);
        $this->login($device, [$once], ['counter' => 0]);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badRegistrations(): iterable
    {
        yield 'a login answer' => [['type' => 'webauthn.get'], 'another kind of ceremony'];
        yield 'another challenge' => [['challenge' => 'AAAA'], 'challenge'];
        yield 'another origin' => [['origin' => 'https://evil.example.test'], 'origin'];
        yield 'the same host on http' => [['origin' => 'http://admin.example.test'], 'origin'];
        yield 'another port' => [['origin' => 'https://admin.example.test:8443'], 'origin'];
        yield 'a frame of another origin' => [['crossOrigin' => true], 'frame'];
        yield 'another relying party id' => [['rpId' => 'evil.example.test'], 'relying party id'];
        yield 'no user verification' => [['flags' => Device::UP | Device::AT], 'present and verified'];
        yield 'no user presence' => [['flags' => Device::UV | Device::AT], 'present and verified'];
        yield 'no credential data' => [['flags' => Device::UP | Device::UV], 'no credential'];
        yield 'backed up but not eligible' => [['flags' => Device::UP | Device::UV | Device::AT | Device::BS], 'backup flags'];
        yield 'bytes that no flag explains' => [['trailing' => "\x00"], 'no flag explains'];
    }

    /** @param array<string, mixed> $spoil */
    #[DataProvider('badRegistrations')]
    public function testARegistrationThatFailsACheckIsRefused(array $spoil, string $message): void
    {
        $device = new Device(CoseKey::ES256, self::ORIGIN, self::RP);

        $this->refused(fn () => $this->register($device, $spoil), $message);
    }

    public function testAPasskeyThatIsRegisteredAlreadyIsRefused(): void
    {
        $device = new Device(CoseKey::ES256, self::ORIGIN, self::RP);
        $rp = $this->relyingParty();
        $challenge = Passkeys::newChallenge();
        $answer = $device->register($rp->registrationOptions($challenge, 'h', 'admin', []));

        $this->refused(fn () => $rp->verifyRegistration($challenge, $answer, [$device->credentialId]), 'registered already');
    }

    public function testAnAttestationObjectThatIsNotAnObjectIsRefused(): void
    {
        $device = new Device(CoseKey::ES256, self::ORIGIN, self::RP);

        $this->refused(fn () => $this->register($device, ['attestationObject' => "\x80"]), 'attestation object');
        $this->refused(fn () => $this->register($device, ['attestationObject' => $device->cbor([['fmt', 'none'], ['attStmt', []]])]), 'attestation object');
        $this->refused(fn () => $this->register($device, ['attestationObject' => 'not cbor']), 'CBOR');
    }

    public function testAnAnswerWithoutAValueOrWithAnImpossibleOneIsRefused(): void
    {
        $device = new Device(CoseKey::ES256, self::ORIGIN, self::RP);
        $rp = $this->relyingParty();
        $challenge = Passkeys::newChallenge();
        $answer = $device->register($rp->registrationOptions($challenge, 'h', 'admin', []));

        $missing = $answer;
        unset($missing['attestationObject']);
        $this->refused(fn () => $rp->verifyRegistration($challenge, $missing, []), 'lacks a value');

        $padded = $answer;
        $padded['clientDataJSON'] = $answer['clientDataJSON'] . '=';
        $this->refused(fn () => $rp->verifyRegistration($challenge, $padded, []), 'base64url');

        $notText = $answer;
        $notText['clientDataJSON'] = ['x'];
        $this->refused(fn () => $rp->verifyRegistration($challenge, $notText, []), 'wrong kind');
    }

    public function testTheTransportsAreAHintAndAreCleaned(): void
    {
        $device = new Device(CoseKey::ES256, self::ORIGIN, self::RP);
        $rp = $this->relyingParty();
        $challenge = Passkeys::newChallenge();
        $answer = $device->register($rp->registrationOptions($challenge, 'h', 'admin', []));
        $answer['transports'] = ['usb', 'usb', 'rocket', 7, 'hybrid'];

        self::assertSame(['usb', 'hybrid'], $rp->verifyRegistration($challenge, $answer, [])->transports);

        $answer['transports'] = 'usb';
        self::assertSame([], $rp->verifyRegistration($challenge, $answer, [])->transports);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badLogins(): iterable
    {
        yield 'a registration answer' => [['type' => 'webauthn.create'], 'another kind of ceremony'];
        yield 'another challenge' => [['challenge' => 'AAAA'], 'challenge'];
        yield 'another origin' => [['origin' => 'https://evil.example.test'], 'origin'];
        yield 'a frame of another origin' => [['crossOrigin' => true], 'frame'];
        yield 'another relying party id' => [['rpId' => 'evil.example.test'], 'relying party id'];
        yield 'no user verification' => [['flags' => Device::UP], 'present and verified'];
        yield 'no user presence' => [['flags' => Device::UV], 'present and verified'];
        yield 'a device that became synced' => [['flags' => Device::UP | Device::UV | Device::BE], 'synced'];
        yield 'backed up but not eligible' => [['flags' => Device::UP | Device::UV | Device::BS], 'backup flags'];
        yield 'bytes that no flag explains' => [['trailing' => "\x00"], 'no flag explains'];
        yield 'another passkey id' => [['id' => 'AAAAAAAA'], 'not known'];
        yield 'the user handle of another user' => [['userHandle' => 'AAAAAAAA'], 'user handle'];
        yield 'a damaged signature' => [['corruptSignature' => true], 'signature'];
    }

    /** @param array<string, mixed> $spoil */
    #[DataProvider('badLogins')]
    public function testALoginThatFailsACheckIsRefused(array $spoil, string $message): void
    {
        $device = new Device(CoseKey::ES256, self::ORIGIN, self::RP);
        $credential = $this->register($device);

        $this->refused(fn () => $this->login($device, [$credential], $spoil), $message);
    }

    public function testASignatureOfAnotherDeviceIsRefused(): void
    {
        $device = new Device(CoseKey::ES256, self::ORIGIN, self::RP);
        $credential = $this->register($device);
        $thief = new Device(CoseKey::ES256, self::ORIGIN, self::RP);

        $this->refused(fn () => $this->login($device, [$credential], ['signedBy' => $thief]), 'signature');
    }

    public function testAnAnswerWithoutAUserHandleIsStillAccepted(): void
    {
        // the handle is checked when the device sends it; a device that leaves it out has the passkey found by its id
        $device = new Device(CoseKey::ES256, self::ORIGIN, self::RP);
        $credential = $this->register($device);

        self::assertSame(1, $this->login($device, [$credential], ['userHandle' => null])->signCount);
    }

    public function testADeviceThatClaimsTheIdOfAnotherPasskeyCannotSign(): void
    {
        $es = new Device(CoseKey::ES256, self::ORIGIN, self::RP);
        $ed = new Device(CoseKey::EDDSA, self::ORIGIN, self::RP);
        $credential = $this->register($es);
        $this->register($ed);
        $ed->credentialId = $es->credentialId;

        $this->refused(fn () => $this->login($ed, [$credential]), 'signature');
    }

    public function testThePasskeysOfTwoDevicesAreToldApartById(): void
    {
        $a = new Device(CoseKey::ES256, self::ORIGIN, self::RP);
        $b = new Device(CoseKey::EDDSA, self::ORIGIN, self::RP);
        $ca = $this->register($a);
        $cb = $this->register($b);

        self::assertSame($b->credentialId, $this->login($b, [$ca, $cb])->id);
        self::assertSame($a->credentialId, $this->login($a, [$ca, $cb])->id);
    }

    /** @return iterable<string, array{string}> */
    public static function browserAlgorithms(): iterable
    {
        yield 'ES256' => ['es256'];
        yield 'EdDSA' => ['eddsa'];
        yield 'RS256' => ['rs256'];
    }

    #[DataProvider('browserAlgorithms')]
    public function testTheAnswersOfARealBrowserAreAccepted(string $name): void
    {
        /** @var array{rpId: string, origin: string, cases: array<string, array{registration: array<string, string>, assertion: array<string, string>}>} $data */
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/webauthn/chrome-virtual.json'), true, 16, JSON_THROW_ON_ERROR);
        $case = $data['cases'][$name];
        $rp = new Passkeys($data['rpId'], $data['origin']);

        $credential = $rp->verifyRegistration(Base64Url::decode($case['registration']['challenge']), $case['registration'] + ['transports' => ['internal']], []);
        self::assertSame(Base64Url::decode($case['registration']['id']), $credential->id);

        $after = $rp->verifyAssertion(Base64Url::decode($case['assertion']['challenge']), $case['assertion'], [$credential], Base64Url::decode($case['registration']['userId']));
        self::assertGreaterThan(0, $after->signCount);

        // the same answer for another site is refused
        $other = new Passkeys($data['rpId'], 'http://localhost:1');
        $this->refused(fn () => $other->verifyAssertion(Base64Url::decode($case['assertion']['challenge']), $case['assertion'], [$credential], Base64Url::decode($case['registration']['userId'])), 'origin');
    }
}
