<?php

declare(strict_types=1);

namespace ThreadSafe\Crypto;

/**
 * Passphrases for backups. A generated one is easy to read aloud, type and copy: five groups of four characters, from an alphabet
 * without look-alikes (no 0, 1, i, l, o), e.g. `kr7m-xq3d-hp9t-w4nc-2vfe`. That is 31 characters per place, 20 places: about 99 bits.
 */
final class Passphrases
{
    public const string ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /** A typed passphrase shorter than this is called weak. */
    public const int MINIMUM_LENGTH = 12;

    public static function generate(): string
    {
        $groups = [];
        for ($group = 0; $group < 5; ++$group) {
            $text = '';
            for ($i = 0; $i < 4; ++$i) {
                $text .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $groups[] = $text;
        }

        return implode('-', $groups);
    }

    /** Whether the text has the shape of a passphrase made by {@see generate()}. */
    public static function isGenerated(string $text): bool
    {
        return preg_match('/^(?:[a-hjkmnp-z2-9]{4}-){4}[a-hjkmnp-z2-9]{4}$/', $text) === 1;
    }

    /**
     * What is wrong with a passphrase that a person typed, or null: the work factor of a passphrase is 2^15, eight times less work for an
     * attacker than the 2^18 of the age tool itself, so only a long one is safe.
     */
    public static function weakness(string $passphrase): ?string
    {
        if (self::isGenerated($passphrase)) {
            return null;
        }
        if (strlen($passphrase) < self::MINIMUM_LENGTH) {
            return 'This passphrase is short (under ' . self::MINIMUM_LENGTH . ' characters). Anyone who has a backup can try passphrases at leisure: use a long one, or one made by "keys passphrase".';
        }

        return null;
    }

    private function __construct()
    {
    }
}
