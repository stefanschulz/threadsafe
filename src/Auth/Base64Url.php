<?php

declare(strict_types=1);

namespace ThreadSafe\Auth;

/**
 * Base64url without padding (RFC 4648 section 5), the form in which WebAuthn data travels between the browser and the server. Reading is
 * strict: only the 64 characters of the alphabet, no padding, no spaces, and no text that another text would also give (the last
 * character must carry no stray bits), so that one answer has one spelling.
 */
final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * @throws PasskeyException
     */
    public static function decode(string $text): string
    {
        if (preg_match('/\A[A-Za-z0-9_-]*\z/', $text) !== 1 || strlen($text) % 4 === 1) {
            throw new PasskeyException('A value of the answer is not base64url.');
        }
        $bytes = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
        if ($bytes === false || self::encode($bytes) !== $text) {
            throw new PasskeyException('A value of the answer is not base64url.');
        }

        return $bytes;
    }
}
