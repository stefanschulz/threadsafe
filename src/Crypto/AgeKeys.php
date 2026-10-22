<?php

declare(strict_types=1);

namespace ThreadSafe\Crypto;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The keys of the `age` format: a secret key is `AGE-SECRET-KEY-1...` (bech32 of 32 random bytes, upper case), the public key (the
 * recipient) is `age1...` (bech32 of the X25519 public key). Key files are the files of `age-keygen`.
 */
final class AgeKeys
{
    /**
     * A new key pair.
     *
     * @return array{secret: string, public: string} the secret key and the public key as text
     *
     * @throws CryptoException `ext-sodium` is missing
     */
    public static function generate(): array
    {
        CryptoException::requireSodium();
        $secret = random_bytes(32);

        return ['secret' => strtoupper(Bech32::encode('age-secret-key-', $secret)), 'public' => Bech32::encode('age', sodium_crypto_scalarmult_base($secret))];
    }

    /**
     * The public key that belongs to a secret key.
     *
     * @throws CryptoException the text is not a secret key
     */
    public static function publicOf(string $secretKey): string
    {
        return (new X25519Identity($secretKey))->recipient();
    }

    /** Whether the text is a public key of the `age` format, checksum included. */
    public static function isRecipient(string $text): bool
    {
        try {
            self::publicBytes($text);

            return true;
        } catch (CryptoException) {
            return false;
        }
    }

    /**
     * @return string the 32 bytes of a public key
     *
     * @throws CryptoException
     */
    public static function publicBytes(string $recipient): string
    {
        [$hrp, $bytes] = Bech32::decode($recipient);
        if ($hrp !== 'age' || strlen($bytes) !== 32) {
            throw CryptoException::malformed('This is not a public key of the age format (it starts with "age1").');
        }

        return $bytes;
    }

    /**
     * @return string the 32 bytes of a secret key
     *
     * @throws CryptoException
     */
    public static function secretBytes(string $secretKey): string
    {
        [$hrp, $bytes] = Bech32::decode(trim($secretKey));
        if ($hrp !== 'age-secret-key-' || strlen($bytes) !== 32) {
            throw CryptoException::malformed('This is not a secret key of the age format (it starts with "AGE-SECRET-KEY-1").');
        }

        return $bytes;
    }

    /**
     * The text of a key file as `age-keygen` writes it, so that the real `age` tool opens it.
     */
    public static function keyFile(string $secretKey, string $publicKey, ?DateTimeImmutable $created = null): string
    {
        return '# created: ' . ($created ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP') . "\n# public key: $publicKey\n$secretKey\n";
    }

    /**
     * The secret keys in the text of a key file (comments and empty lines are skipped; `age` accepts several keys in one file).
     *
     * @return list<string>
     *
     * @throws CryptoException a line that is not a comment is not a secret key, or there is none
     */
    public static function secretKeysIn(string $content): array
    {
        $keys = [];
        foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            self::secretBytes($line);
            $keys[] = $line;
        }
        if ($keys === []) {
            throw CryptoException::malformed('There is no secret key (AGE-SECRET-KEY-1...) in this file.');
        }

        return $keys;
    }

    private function __construct()
    {
    }
}
