<?php

declare(strict_types=1);

namespace ThreadSafe\Crypto;

/**
 * The base64 of the `age` header: the standard alphabet without padding, and nothing but the canonical form when reading (a header that
 * two programs read differently is a way to smuggle something past one of them).
 */
final class Base64
{
    public static function encode(string $raw): string
    {
        return rtrim(base64_encode($raw), '=');
    }

    /**
     * @throws CryptoException the text is not canonical unpadded base64
     */
    public static function decode(string $text): string
    {
        if (preg_match('~^[A-Za-z0-9+/]*$~', $text) !== 1 || strlen($text) % 4 === 1) {
            throw CryptoException::malformed('The header of the file holds text that is not valid base64.');
        }
        $raw = base64_decode($text . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
        if ($raw === false || self::encode($raw) !== $text) {
            throw CryptoException::malformed('The header of the file holds text that is not valid base64.');
        }

        return $raw;
    }

    private function __construct()
    {
    }
}
