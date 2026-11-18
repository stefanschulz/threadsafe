<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Auth\AuthenticatorData;
use ThreadSafe\Auth\Base64Url;
use ThreadSafe\Auth\ClientData;
use ThreadSafe\Auth\CoseKey;
use ThreadSafe\Auth\PasskeyException;
use ThreadSafe\Tests\Support\SoftwareAuthenticator;

/**
 * The small readers under the checks of the passkeys: base64url, the authenticator data and the client data JSON (docs/PHASE14.md 3.2).
 */
final class PasskeyPartsTest extends TestCase
{
    /** @return iterable<string, array{string, string}> bytes, text */
    public static function base64url(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'f' => ['f', 'Zg'];
        yield 'fo' => ['fo', 'Zm8'];
        yield 'foo' => ['foo', 'Zm9v'];
        yield 'bytes that need - and _' => ["\xfb\xff\xbe", '-_--'];
    }

    #[DataProvider('base64url')]
    public function testBase64UrlWritesAndReadsWithoutPadding(string $bytes, string $text): void
    {
        self::assertSame($text, Base64Url::encode($bytes));
        self::assertSame($bytes, Base64Url::decode($text));
    }

    /** @return iterable<string, array{string}> */
    public static function notBase64url(): iterable
    {
        yield 'padding' => ['Zg=='];
        yield 'plus' => ['Zm+v'];
        yield 'slash' => ['Zm/v'];
        yield 'a space' => ['Zm 9v'];
        yield 'a line break' => ["Zm9v\n"];
        yield 'a length that cannot be' => ['Zm9vY'];
        yield 'stray bits in the last character' => ['Zh'];
    }

    #[DataProvider('notBase64url')]
    public function testBase64UrlRefusesWhatIsNotItsOwnSpelling(string $text): void
    {
        $this->expectException(PasskeyException::class);
        Base64Url::decode($text);
    }

    /** @return array{0: string, 1: SoftwareAuthenticator} */
    private static function registrationData(int $flags = 0x45, string $trailing = ''): array
    {
        $device = new SoftwareAuthenticator();
        $data = hash('sha256', 'admin.example.test', true) . chr($flags) . pack('N', 5);
        if (($flags & 0x40) !== 0) {
            $data .= str_repeat("\x07", 16) . pack('n', strlen($device->credentialId)) . $device->credentialId . $device->coseKey();
        }

        return [$data . $trailing, $device];
    }

    public function testAuthenticatorDataOfARegistrationIsRead(): void
    {
        [$bytes, $device] = self::registrationData(0x45 | 0x08 | 0x10);
        $data = AuthenticatorData::parse($bytes);

        self::assertSame(hash('sha256', 'admin.example.test', true), $data->rpIdHash);
        self::assertTrue($data->userPresent() && $data->userVerified() && $data->attested());
        self::assertTrue($data->backupEligible() && $data->backupState() && $data->backupFlagsMakeSense());
        self::assertSame(5, $data->counter);
        self::assertSame(str_repeat("\x07", 16), $data->aaguid);
        self::assertSame($device->credentialId, $data->credentialId);
        self::assertNotNull($data->key);
        self::assertSame(CoseKey::ES256, $data->key->algorithm);
        self::assertSame($device->coseKey(), $data->key->toCbor());
    }

    public function testAuthenticatorDataOfALoginHasNoCredential(): void
    {
        [$bytes] = self::registrationData(0x05);
        $data = AuthenticatorData::parse($bytes);

        self::assertNull($data->credentialId);
        self::assertNull($data->key);
        self::assertFalse($data->attested());
    }

    public function testExtensionsAreReadAndSkipped(): void
    {
        [$bytes] = self::registrationData(0x45 | 0x80, "\xa1\x6bhmac-secret\xf5");
        self::assertSame(5, AuthenticatorData::parse($bytes)->counter);
    }

    public function testBackedUpWithoutBeingEligibleContradictsItself(): void
    {
        [$bytes] = self::registrationData(0x05 | 0x10);
        self::assertFalse(AuthenticatorData::parse($bytes)->backupFlagsMakeSense());
    }

    /** @return iterable<string, array{callable(): string}> */
    public static function brokenData(): iterable
    {
        yield 'too short' => [static fn (): string => str_repeat('a', 36)];
        yield 'credential data cut' => [static fn (): string => substr(self::registrationData()[0], 0, 37 + 10)];
        yield 'credential id cut' => [static fn (): string => substr(self::registrationData()[0], 0, 37 + 18 + 5)];
        yield 'key cut' => [static fn (): string => substr(self::registrationData()[0], 0, -5)];
        yield 'an id of length 0' => [static function (): string {
            [$bytes, $device] = self::registrationData();

            return substr($bytes, 0, 37 + 16) . "\x00\x00" . $device->coseKey();
        }];
        yield 'an id longer than 1023' => [static function (): string {
            [$bytes, $device] = self::registrationData();

            return substr($bytes, 0, 37 + 16) . pack('n', 1024) . str_repeat('x', 1024) . $device->coseKey();
        }];
        yield 'bytes left over' => [static fn (): string => self::registrationData(0x45, "\x00")[0]];
        yield 'bytes without a flag' => [static fn (): string => self::registrationData(0x05, "\x00")[0]];
        yield 'extensions that are not a map' => [static fn (): string => self::registrationData(0x45 | 0x80, "\x01")[0]];
        yield 'extensions that are missing' => [static fn (): string => self::registrationData(0x45 | 0x80)[0]];
    }

    /** @param callable(): string $bytes */
    #[DataProvider('brokenData')]
    public function testBrokenAuthenticatorDataIsRefused(callable $bytes): void
    {
        $this->expectException(PasskeyException::class);
        AuthenticatorData::parse($bytes());
    }

    public function testTheClientDataIsReadAsJsonAndUnknownMembersAreIgnored(): void
    {
        $data = ClientData::parse('{"type":"webauthn.get","challenge":"abc","origin":"https://a.test","crossOrigin":false,"other_keys_can_be_added_here":"x","topOrigin":"https://b.test"}');

        self::assertSame(['webauthn.get', 'abc', 'https://a.test', false], [$data->type, $data->challenge, $data->origin, $data->crossOrigin]);
    }

    public function testTheMemberCrossOriginMayBeMissing(): void
    {
        self::assertFalse(ClientData::parse('{"type":"t","challenge":"c","origin":"o"}')->crossOrigin);
    }

    /** @return iterable<string, array{string}> */
    public static function brokenClientData(): iterable
    {
        yield 'not JSON' => ['type=webauthn.get'];
        yield 'a list' => ['["webauthn.get","c","o"]'];
        yield 'a string' => ['"x"'];
        yield 'no type' => ['{"challenge":"c","origin":"o"}'];
        yield 'no challenge' => ['{"type":"t","origin":"o"}'];
        yield 'no origin' => ['{"type":"t","challenge":"c"}'];
        yield 'a number as the challenge' => ['{"type":"t","challenge":1,"origin":"o"}'];
        yield 'crossOrigin as text' => ['{"type":"t","challenge":"c","origin":"o","crossOrigin":"false"}'];
        yield 'nesting that is too deep' => ['{"type":"t","challenge":"c","origin":"o","x":' . str_repeat('[', 20) . str_repeat(']', 20) . '}'];
    }

    #[DataProvider('brokenClientData')]
    public function testBrokenClientDataIsRefused(string $json): void
    {
        $this->expectException(PasskeyException::class);
        ClientData::parse($json);
    }

    public function testClientDataThatIsTooLargeIsRefused(): void
    {
        $this->expectException(PasskeyException::class);
        ClientData::parse('{"type":"t","challenge":"c","origin":"o","x":"' . str_repeat('a', ClientData::MAX_BYTES) . '"}');
    }
}
