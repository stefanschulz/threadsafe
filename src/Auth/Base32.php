<?php

declare(strict_types=1);

namespace ThreadSafe\Auth;

use InvalidArgumentException;

/**
 * Base32 as RFC 4648 writes it (the alphabet A-Z and 2-7), which is how an authenticator app is given a secret. Writing leaves out the
 * padding; reading accepts lower case, spaces, hyphens and padding, because a person types the key from a screen in groups.
 */
final class Base32
{
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $bytes): string
    {
        $out = '';
        $buffer = 0;
        $bits = 0;
        foreach (str_split($bytes) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::ALPHABET[($buffer >> $bits) & 31];
            }
            $buffer &= (1 << $bits) - 1;
        }
        if ($bits > 0) {
            $out .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
        }

        return $out;
    }

    /**
     * @throws InvalidArgumentException the text holds a character that is not in the alphabet
     */
    public static function decode(string $text): string
    {
        $text = rtrim(strtoupper(str_replace([' ', '-'], '', $text)), '=');
        $out = '';
        $buffer = 0;
        $bits = 0;
        foreach ($text === '' ? [] : str_split($text) as $character) {
            $value = strpos(self::ALPHABET, $character);
            if ($value === false) {
                throw new InvalidArgumentException('The key holds a character that is not part of a Base32 key (letters A to Z and digits 2 to 7).');
            }
            $buffer = ($buffer << 5) | $value;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($buffer >> $bits) & 255);
                $buffer &= (1 << $bits) - 1;
            }
        }

        return $out;
    }
}
