<?php

declare(strict_types=1);

namespace ThreadSafe\Crypto;

/**
 * Bech32 (BIP 173) as the `age` keys use it: a human readable part, the separator `1`, the data in 32 characters and a six character
 * checksum. Unlike the bitcoin addresses the length is not limited to 90 characters, and an `age` secret key is written in upper case
 * (all upper or all lower case is accepted when reading, a mixture is not).
 */
final class Bech32
{
    private const string CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

    /** @var list<int> */
    private const array GENERATOR = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];

    /**
     * @param string $data raw bytes
     *
     * @return string lower case; the caller upper-cases a secret key
     */
    public static function encode(string $hrp, string $data): string
    {
        $values = self::toFiveBits(array_values(unpack('C*', $data) ?: []));
        $out = $hrp . '1';
        foreach ([...$values, ...self::checksum($hrp, $values)] as $value) {
            $out .= self::CHARSET[$value];
        }

        return $out;
    }

    /**
     * @return array{string, string} the human readable part (lower case) and the raw bytes
     *
     * @throws CryptoException the text is not valid bech32
     */
    public static function decode(string $text): array
    {
        if ($text !== strtolower($text) && $text !== strtoupper($text)) {
            throw CryptoException::malformed('The key mixes upper and lower case letters.');
        }
        $text = strtolower($text);
        $split = strrpos($text, '1');
        if ($split === false || $split < 1 || strlen($text) - $split - 1 < 6) {
            throw CryptoException::malformed('The key is not in the bech32 form (a name, "1", the data and a checksum).');
        }
        $hrp = substr($text, 0, $split);
        $values = [];
        foreach (str_split(substr($text, $split + 1)) as $character) {
            $position = strpos(self::CHARSET, $character);
            if ($position === false) {
                throw CryptoException::malformed('The key holds a character that cannot be part of a key.');
            }
            $values[] = $position;
        }
        if (self::polymod([...self::expand($hrp), ...$values]) !== 1) {
            throw CryptoException::malformed('The checksum of the key is wrong: a character was mistyped or lost.');
        }
        $bytes = self::toBytes(array_slice($values, 0, -6));
        if ($bytes === null) {
            throw CryptoException::malformed('The data of the key is not valid.');
        }

        return [$hrp, pack('C*', ...$bytes)];
    }

    /**
     * @param list<int> $values
     */
    private static function polymod(array $values): int
    {
        $check = 1;
        foreach ($values as $value) {
            $top = $check >> 25;
            $check = (($check & 0x1ffffff) << 5) ^ $value;
            for ($i = 0; $i < 5; ++$i) {
                if ((($top >> $i) & 1) !== 0) {
                    $check ^= self::GENERATOR[$i];
                }
            }
        }

        return $check;
    }

    /**
     * @return list<int>
     */
    private static function expand(string $hrp): array
    {
        $out = [];
        foreach (str_split($hrp) as $character) {
            $out[] = ord($character) >> 5;
        }
        $out[] = 0;
        foreach (str_split($hrp) as $character) {
            $out[] = ord($character) & 31;
        }

        return $out;
    }

    /**
     * @param list<int> $values
     *
     * @return list<int>
     */
    private static function checksum(string $hrp, array $values): array
    {
        $mod = self::polymod([...self::expand($hrp), ...$values, 0, 0, 0, 0, 0, 0]) ^ 1;
        $out = [];
        for ($i = 0; $i < 6; ++$i) {
            $out[] = ($mod >> (5 * (5 - $i))) & 31;
        }

        return $out;
    }

    /**
     * @param list<int> $bytes
     *
     * @return list<int>
     */
    private static function toFiveBits(array $bytes): array
    {
        $accumulator = 0;
        $bits = 0;
        $out = [];
        foreach ($bytes as $value) {
            $accumulator = (($accumulator << 8) | $value) & 0xffffffff;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out[] = ($accumulator >> $bits) & 31;
            }
        }
        if ($bits > 0) {
            $out[] = ($accumulator << (5 - $bits)) & 31;
        }

        return $out;
    }

    /**
     * @param list<int> $values
     *
     * @return list<int>|null null if the padding is wrong
     */
    private static function toBytes(array $values): ?array
    {
        $accumulator = 0;
        $bits = 0;
        $out = [];
        foreach ($values as $value) {
            $accumulator = (($accumulator << 5) | $value) & 0xffffffff;
            $bits += 5;
            while ($bits >= 8) {
                $bits -= 8;
                $out[] = ($accumulator >> $bits) & 255;
            }
        }
        if ($bits >= 5 || (($accumulator << (8 - $bits)) & 255) !== 0) {
            return null;
        }

        return $out;
    }
}
