<?php

declare(strict_types=1);

namespace ThreadSafe\Auth;

/**
 * The recovery codes of the second factor (docs/PHASE11.md 3.2): ten codes of 12 characters, written XXXX-XXXX-XXXX, from an alphabet
 * without the characters that look alike (no 0, 1, I, O). 12 characters of 32 are 60 bits, so a plain SHA-256 is the right way to keep
 * them: there is nothing to guess from, and Argon2id on ten codes per login attempt would only cost time.
 */
final class RecoveryCodes
{
    public const int COUNT = 10;

    private const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const int LENGTH = 12;

    /**
     * New codes, to be shown once.
     *
     * @return list<string> written with hyphens, e.g. "K7QD-2MWX-9RHT"
     */
    public static function generate(int $count = self::COUNT): array
    {
        $codes = [];
        while (count($codes) < $count) {
            $plain = '';
            foreach (str_split(random_bytes(self::LENGTH)) as $byte) {
                $plain .= self::ALPHABET[ord($byte) & 31];
            }
            $codes[$plain] = implode('-', str_split($plain, 4));
        }

        return array_values($codes);
    }

    /** What is stored of a code: the SHA-256 of its characters, case and hyphens and spaces ignored. */
    public static function hash(string $code): string
    {
        return hash('sha256', self::normalize($code));
    }

    /** Whether the text could be a recovery code, so that the second step knows which kind of code was typed. */
    public static function looksLike(string $typed): bool
    {
        return preg_match('/^[' . self::ALPHABET . ']{' . self::LENGTH . '}$/', self::normalize($typed)) === 1;
    }

    private static function normalize(string $code): string
    {
        return strtoupper(str_replace([' ', '-'], '', $code));
    }
}
