<?php

declare(strict_types=1);

namespace ThreadSafe\Crypto;

use RuntimeException;

/**
 * Something is wrong with a key, an `age` file or a passphrase; the message says what, in words for the person. The code tells a caller
 * which kind of trouble it is without parsing the message.
 */
final class CryptoException extends RuntimeException
{
    /** The text is not a key, or not an `age` file. */
    public const int MALFORMED = 1;

    /** No key or passphrase that was given opens the file. */
    public const int NO_MATCH = 2;

    /** The file is damaged, cut short, or has been changed. */
    public const int DAMAGED = 3;

    /** The work factor of a passphrase is more than this host can do within its memory limit. */
    public const int TOO_EXPENSIVE = 4;

    /** `ext-sodium` is missing. */
    public const int NO_SODIUM = 5;

    public static function malformed(string $message): self
    {
        return new self($message, self::MALFORMED);
    }

    public static function noMatch(string $message): self
    {
        return new self($message, self::NO_MATCH);
    }

    public static function damaged(string $message): self
    {
        return new self($message, self::DAMAGED);
    }

    public static function tooExpensive(string $message): self
    {
        return new self($message, self::TOO_EXPENSIVE);
    }

    /**
     * @throws self `ext-sodium` is not loaded
     */
    public static function requireSodium(): void
    {
        if (!function_exists('sodium_crypto_aead_chacha20poly1305_ietf_encrypt') || !function_exists('sodium_crypto_scalarmult')) {
            throw new self('Encryption needs the PHP extension "sodium" (ext-sodium), which is not loaded on this server.', self::NO_SODIUM);
        }
    }
}
