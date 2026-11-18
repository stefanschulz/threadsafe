<?php

declare(strict_types=1);

namespace ThreadSafe\Auth;

use SodiumException;

/**
 * The public key of a passkey, in the form that a device sends it (a COSE key, RFC 9052, as CBOR), and the check of a signature with it.
 * Three algorithms are known, the ones that browsers and devices offer (docs/PHASE14.md 3.2):
 *
 * - ES256 (-7): ECDSA on the curve P-256 with SHA-256; the signature is DER; needs `ext-openssl`;
 * - EdDSA (-8): Ed25519; the signature is 64 bytes; needs `ext-sodium`;
 * - RS256 (-257): RSASSA-PKCS1-v1_5 with SHA-256, at least 2048 bits; needs `ext-openssl`.
 *
 * The key is kept as the CBOR bytes it came in ({@see toCbor()}), so that the store holds exactly what the device sent.
 */
final class CoseKey
{
    public const int ES256 = -7;
    public const int EDDSA = -8;
    public const int RS256 = -257;

    private function __construct(
        public readonly int $algorithm,
        private readonly string $key,
        private readonly string $cbor,
    ) {
    }

    /**
     * The algorithms this PHP can check, in the order that the registration offers them.
     *
     * @return list<int>
     */
    public static function supported(): array
    {
        $list = [];
        if (extension_loaded('openssl')) {
            $list[] = self::ES256;
        }
        if (extension_loaded('sodium')) {
            $list[] = self::EDDSA;
        }
        if (extension_loaded('openssl')) {
            $list[] = self::RS256;
        }

        return $list;
    }

    /**
     * @throws PasskeyException the bytes are not a key of one of the three kinds, or this PHP cannot check that kind
     */
    public static function fromCbor(string $cbor): self
    {
        $map = Cbor::decode($cbor);
        if (!is_array($map) || array_is_list($map) && $map !== []) {
            throw new PasskeyException('The public key is not a COSE key (a map is expected).');
        }
        $algorithm = $map[3] ?? null;
        if (!is_int($algorithm) || !in_array($algorithm, [self::ES256, self::EDDSA, self::RS256], true)) {
            throw new PasskeyException('The public key uses an algorithm that this program does not know.');
        }
        if (!in_array($algorithm, self::supported(), true)) {
            throw new PasskeyException('This server cannot check that kind of key (the PHP extension is missing).');
        }

        return match ($algorithm) {
            self::ES256 => self::es256($map, $cbor),
            self::EDDSA => self::eddsa($map, $cbor),
            default => self::rs256($map, $cbor),
        };
    }

    /** The key as it was given, to be stored. */
    public function toCbor(): string
    {
        return $this->cbor;
    }

    /**
     * True only if the signature belongs to the message and this key. A signature that is the wrong size or form is false, not an error.
     */
    public function verify(string $message, string $signature): bool
    {
        if ($this->algorithm === self::EDDSA) {
            if ($signature === '' || $this->key === '') {
                return false;
            }
            try {
                return sodium_crypto_sign_verify_detached($signature, $message, $this->key);
            } catch (SodiumException) {
                return false;
            }
        }
        $result = openssl_verify($message, $signature, $this->key, OPENSSL_ALGO_SHA256);
        self::clearOpensslErrors();

        return $result === 1;
    }

    /** @param array<int|string, mixed> $map */
    private static function es256(array $map, string $cbor): self
    {
        $x = $map[-2] ?? null;
        $y = $map[-3] ?? null;
        if (($map[1] ?? null) !== 2 || ($map[-1] ?? null) !== 1 || !is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
            throw new PasskeyException('The ES256 key is not a point of the curve P-256 in the form of RFC 9053.');
        }
        $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
        \assert($spki !== false);

        return new self(self::ES256, self::usable(self::pem($spki . "\x04" . $x . $y), 'EC', 0), $cbor);
    }

    /** @param array<int|string, mixed> $map */
    private static function eddsa(array $map, string $cbor): self
    {
        $x = $map[-2] ?? null;
        if (($map[1] ?? null) !== 1 || ($map[-1] ?? null) !== 6 || !is_string($x) || strlen($x) !== 32) {
            throw new PasskeyException('The EdDSA key is not an Ed25519 key in the form of RFC 9053.');
        }

        return new self(self::EDDSA, $x, $cbor);
    }

    /** @param array<int|string, mixed> $map */
    private static function rs256(array $map, string $cbor): self
    {
        $n = $map[-1] ?? null;
        $e = $map[-2] ?? null;
        if (($map[1] ?? null) !== 3 || !is_string($n) || !is_string($e) || strlen($e) < 1 || strlen($e) > 8 || strlen($n) < 256 || strlen($n) > 1024) {
            throw new PasskeyException('The RS256 key is not an RSA key of 2048 to 8192 bits in the form of RFC 8230.');
        }
        $rsa = self::der(0x30, self::derInteger($n) . self::derInteger($e));
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        \assert($algorithm !== false);

        return new self(self::RS256, self::usable(self::pem(self::der(0x30, $algorithm . self::der(0x03, "\0" . $rsa))), 'RSA', 2048), $cbor);
    }

    /** The key in PEM form, after OpenSSL has accepted it (it refuses a point that is not on the curve). */
    private static function usable(string $pem, string $type, int $minimumBits): string
    {
        $key = openssl_pkey_get_public($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        self::clearOpensslErrors();
        if ($details === false || ($details['type'] ?? null) !== ($type === 'EC' ? OPENSSL_KEYTYPE_EC : OPENSSL_KEYTYPE_RSA) || ($details['bits'] ?? 0) < $minimumBits) {
            throw new PasskeyException('OpenSSL does not accept the public key.');
        }

        return $pem;
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function der(int $tag, string $body): string
    {
        $length = strlen($body);
        if ($length < 128) {
            return chr($tag) . chr($length) . $body;
        }
        $bytes = ltrim(pack('N', $length), "\0");

        return chr($tag) . chr(0x80 | strlen($bytes)) . $bytes . $body;
    }

    private static function derInteger(string $bigEndian): string
    {
        $bigEndian = ltrim($bigEndian, "\0");
        if ($bigEndian === '') {
            $bigEndian = "\0";
        }

        return self::der(0x02, ((ord($bigEndian[0]) & 0x80) !== 0 ? "\0" : '') . $bigEndian);
    }

    /** OpenSSL keeps its errors in a queue; a stale one would show up in an unrelated call later. */
    private static function clearOpensslErrors(): void
    {
        while (openssl_error_string() !== false) {
        }
    }
}
