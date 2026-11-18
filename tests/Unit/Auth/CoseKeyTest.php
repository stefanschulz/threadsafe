<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreadSafe\Auth\Cbor;
use ThreadSafe\Auth\CoseKey;
use ThreadSafe\Auth\PasskeyException;

/**
 * The public key of a passkey and its signatures. The oracle is a real browser: `tests/fixtures/webauthn/chrome-virtual.json` holds
 * answers of the virtual authenticator of headless Chrome (one registration and one assertion per algorithm, `tools/spikes/webauthn-virtual.mjs`);
 * the keys are read from the attestation object and every assertion must verify. The second oracle is OpenSSL and sodium making keys and
 * signatures in the test (docs/PHASE14.md 4.1).
 */
final class CoseKeyTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function algorithms(): iterable
    {
        yield 'ES256' => ['es256', CoseKey::ES256];
        yield 'EdDSA' => ['eddsa', CoseKey::EDDSA];
        yield 'RS256' => ['rs256', CoseKey::RS256];
    }

    /** @return array{key: CoseKey, signed: string, signature: string, keyBytes: string} */
    private static function vector(string $name): array
    {
        $file = dirname(__DIR__, 2) . '/fixtures/webauthn/chrome-virtual.json';
        /** @var array{cases: array<string, array{registration: array<string, string>, assertion: array<string, string>}>} $data */
        $data = json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
        $case = $data['cases'][$name];

        $attestation = Cbor::decode(self::b64u($case['registration']['attestationObject']));
        self::assertIsArray($attestation);
        $authData = $attestation['authData'];
        self::assertIsString($authData);
        // rpIdHash 32, flags 1, counter 4, aaguid 16, then the length of the credential id (2), the id and the key
        $idLength = unpack('n', substr($authData, 53, 2));
        self::assertIsArray($idLength);
        $keyStart = 55 + $idLength[1];
        [, $end] = Cbor::decodePrefix($authData, $keyStart);
        $keyBytes = substr($authData, $keyStart, $end - $keyStart);

        $assertion = $case['assertion'];
        $signed = self::b64u($assertion['authenticatorData']) . hash('sha256', self::b64u($assertion['clientDataJSON']), true);

        return ['key' => CoseKey::fromCbor($keyBytes), 'signed' => $signed, 'signature' => self::b64u($assertion['signature']), 'keyBytes' => $keyBytes];
    }

    private static function b64u(string $text): string
    {
        $bytes = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
        self::assertIsString($bytes);

        return $bytes;
    }

    #[DataProvider('algorithms')]
    public function testTheAssertionOfARealBrowserVerifies(string $name, int $algorithm): void
    {
        $v = self::vector($name);

        self::assertSame($algorithm, $v['key']->algorithm);
        self::assertTrue($v['key']->verify($v['signed'], $v['signature']));
    }

    #[DataProvider('algorithms')]
    public function testAChangedMessageOrSignatureIsRefused(string $name, int $algorithm): void
    {
        $v = self::vector($name);

        self::assertSame($algorithm, $v['key']->algorithm);
        self::assertFalse($v['key']->verify($v['signed'] . 'x', $v['signature']));
        self::assertFalse($v['key']->verify(substr($v['signed'], 0, -1), $v['signature']));
        $flipped = $v['signature'];
        $flipped[strlen($flipped) - 1] = chr(ord($flipped[strlen($flipped) - 1]) ^ 1);
        self::assertFalse($v['key']->verify($v['signed'], $flipped));
        self::assertFalse($v['key']->verify($v['signed'], ''));
        self::assertFalse($v['key']->verify($v['signed'], str_repeat("\x01", 7)));
    }

    #[DataProvider('algorithms')]
    public function testTheKeyIsKeptExactlyAsItCame(string $name, int $algorithm): void
    {
        $v = self::vector($name);

        self::assertSame($algorithm, $v['key']->algorithm);
        self::assertSame($v['keyBytes'], $v['key']->toCbor());
        self::assertSame($v['key']->algorithm, CoseKey::fromCbor($v['key']->toCbor())->algorithm);
    }

    public function testAnotherKeyDoesNotVerifyTheSignature(): void
    {
        $es = self::vector('es256');
        $other = self::vector('eddsa');

        self::assertFalse($other['key']->verify($es['signed'], $es['signature']));
        self::assertFalse($es['key']->verify($other['signed'], $other['signature']));
    }

    public function testAKeyAndASignatureMadeByOpenSslVerify(): void
    {
        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($private);
        $details = openssl_pkey_get_details($private);
        self::assertIsArray($details);
        $key = CoseKey::fromCbor(self::coseEc($details['ec']['x'], $details['ec']['y']));

        self::assertTrue(openssl_sign('message', $signature, $private, OPENSSL_ALGO_SHA256));
        self::assertTrue($key->verify('message', $signature));
        self::assertFalse($key->verify('other message', $signature));
    }

    public function testAnRsaKeyOfFewerThan2048BitsIsRefused(): void
    {
        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024]);
        self::assertNotFalse($private);
        $details = openssl_pkey_get_details($private);
        self::assertIsArray($details);

        $this->expectException(PasskeyException::class);
        CoseKey::fromCbor(self::cborMap([[1, 3], [3, -257], [-1, $details['rsa']['n']], [-2, $details['rsa']['e']]]));
    }

    public function testAnRsaKeyOf2048BitsIsRead(): void
    {
        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($private);
        $details = openssl_pkey_get_details($private);
        self::assertIsArray($details);
        $key = CoseKey::fromCbor(self::cborMap([[1, 3], [3, -257], [-1, $details['rsa']['n']], [-2, $details['rsa']['e']]]));

        self::assertTrue(openssl_sign('message', $signature, $private, OPENSSL_ALGO_SHA256));
        self::assertTrue($key->verify('message', $signature));
    }

    public function testASodiumKeyAndSignatureVerify(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $key = CoseKey::fromCbor(self::cborMap([[1, 1], [3, -8], [-1, 6], [-2, sodium_crypto_sign_publickey($pair)]]));
        $signature = sodium_crypto_sign_detached('message', sodium_crypto_sign_secretkey($pair));

        self::assertTrue($key->verify('message', $signature));
        self::assertFalse($key->verify('other', $signature));
        self::assertFalse($key->verify('message', substr($signature, 0, 63)));
    }

    public function testAPointThatIsNotOnTheCurveIsRefused(): void
    {
        $this->expectException(PasskeyException::class);
        $this->expectExceptionMessageMatches('/OpenSSL does not accept/');
        CoseKey::fromCbor(self::coseEc(str_repeat("\x01", 32), str_repeat("\x02", 32)));
    }

    /** @return iterable<string, array{string}> hex of keys that are not usable */
    public static function badKeys(): iterable
    {
        yield 'not a map' => [bin2hex(self::cborList([1, 2]))];
        yield 'an empty map' => ['a0'];
        yield 'an unknown algorithm' => [bin2hex(self::cborMap([[1, 2], [3, -36], [-1, 3], [-2, str_repeat('a', 32)], [-3, str_repeat('b', 32)]]))];
        yield 'no algorithm' => [bin2hex(self::cborMap([[1, 2], [-1, 1], [-2, str_repeat('a', 32)], [-3, str_repeat('b', 32)]]))];
        yield 'ES256 with the wrong key type' => [bin2hex(self::cborMap([[1, 1], [3, -7], [-1, 1], [-2, str_repeat('a', 32)], [-3, str_repeat('b', 32)]]))];
        yield 'ES256 with another curve' => [bin2hex(self::cborMap([[1, 2], [3, -7], [-1, 2], [-2, str_repeat('a', 32)], [-3, str_repeat('b', 32)]]))];
        yield 'ES256 with a short x' => [bin2hex(self::cborMap([[1, 2], [3, -7], [-1, 1], [-2, str_repeat('a', 31)], [-3, str_repeat('b', 32)]]))];
        yield 'ES256 without y' => [bin2hex(self::cborMap([[1, 2], [3, -7], [-1, 1], [-2, str_repeat('a', 32)]]))];
        yield 'EdDSA with the wrong curve' => [bin2hex(self::cborMap([[1, 1], [3, -8], [-1, 7], [-2, str_repeat('a', 32)]]))];
        yield 'EdDSA with a short key' => [bin2hex(self::cborMap([[1, 1], [3, -8], [-1, 6], [-2, str_repeat('a', 31)]]))];
        yield 'RS256 without the exponent' => [bin2hex(self::cborMap([[1, 3], [3, -257], [-1, str_repeat('a', 256)]]))];
        yield 'RS256 with a short modulus' => [bin2hex(self::cborMap([[1, 3], [3, -257], [-1, str_repeat('a', 128)], [-2, "\x01\x00\x01"]]))];
        yield 'a text as the algorithm' => [bin2hex(self::cborMap([[1, 2], [3, 'ES256']]))];
    }

    #[DataProvider('badKeys')]
    public function testAKeyThatIsNotUsableIsRefused(string $hex): void
    {
        $this->expectException(PasskeyException::class);
        CoseKey::fromCbor((string) hex2bin($hex));
    }

    public function testTheAlgorithmsOfThisPhpAreListedInTheOrderOfTheRegistration(): void
    {
        if (!extension_loaded('openssl') || !extension_loaded('sodium')) {
            self::markTestSkipped('needs openssl and sodium');
        }
        self::assertSame([CoseKey::ES256, CoseKey::EDDSA, CoseKey::RS256], CoseKey::supported());
    }

    private static function coseEc(string $x, string $y): string
    {
        return self::cborMap([[1, 2], [3, -7], [-1, 1], [-2, $x], [-3, $y]]);
    }

    /** @param list<array{int, int|string}> $pairs integer keys; a string value is written as a byte string */
    private static function cborMap(array $pairs): string
    {
        $out = self::head(5, count($pairs));
        foreach ($pairs as [$key, $value]) {
            $out .= self::integer($key) . (is_string($value) ? self::head(2, strlen($value)) . $value : self::integer($value));
        }

        return $out;
    }

    /** @param list<int> $items */
    private static function cborList(array $items): string
    {
        return self::head(4, count($items)) . implode('', array_map(self::integer(...), $items));
    }

    private static function integer(int $n): string
    {
        return $n >= 0 ? self::head(0, $n) : self::head(1, -1 - $n);
    }

    private static function head(int $major, int $n): string
    {
        $m = $major << 5;

        return match (true) {
            $n < 24 => chr($m | $n),
            $n < 256 => chr($m | 24) . chr($n),
            default => chr($m | 25) . pack('n', $n),
        };
    }
}
