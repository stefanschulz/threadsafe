<?php

declare(strict_types=1);

namespace ThreadSafe\Auth;

/**
 * Time-based one-time passwords as RFC 6238 defines them, with the settings that every authenticator app uses: HMAC-SHA1, six digits,
 * steps of 30 seconds (docs/PHASE11.md 3.1). Plain PHP: `hash_hmac` and `random_bytes` are all it needs.
 *
 * {@see verify()} accepts the steps before, at and after the one of the given time (a clock that runs a little wrong, a code typed
 * slowly), compares in constant time, and accepts a step only once: the caller keeps the step it got back and hands it in as the last
 * accepted one, so that a code seen over a shoulder does not log in a second time.
 */
final class Totp
{
    public const int PERIOD = 30;

    public const int DIGITS = 6;

    /** Steps accepted on each side of the current one. */
    public const int WINDOW = 1;

    private const int SECRET_BYTES = 20;

    /** A new secret, written in Base32 (32 characters), as the app wants it. */
    public static function newSecret(): string
    {
        return Base32::encode(random_bytes(self::SECRET_BYTES));
    }

    /** The number of the 30-second step that a moment (seconds since 1970) is in. */
    public static function step(int $time): int
    {
        return intdiv($time, self::PERIOD);
    }

    /**
     * The code of one step.
     *
     * @param string $secret the secret in Base32
     *
     * @throws \InvalidArgumentException the secret is not Base32
     */
    public static function code(string $secret, int $step, int $digits = self::DIGITS): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), Base32::decode($secret), true);
        $offset = ord($hash[19]) & 0x0f;
        $number = unpack('N', substr($hash, $offset, 4));
        $value = (is_array($number) ? (int) $number[1] : 0) & 0x7fffffff;

        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Checks what a person typed.
     *
     * @param string $secret   the secret in Base32
     * @param string $typed    the six digits (spaces and hyphens are ignored, as an app shows "123 456")
     * @param int    $time     now, in seconds since 1970
     * @param int    $lastStep the step that was accepted last time (0 = none); a code of this step or an older one is refused
     *
     * @return int|null the step whose code was right (to be kept as the last accepted one), or null
     */
    public static function verify(string $secret, string $typed, int $time, int $lastStep = 0): ?int
    {
        $typed = str_replace([' ', '-'], '', $typed);
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $typed) !== 1) {
            return null;
        }

        $accepted = null;
        $now = self::step($time);
        // every step in the window is compared, whether or not an earlier one matched, so that the time taken tells nothing
        for ($step = $now - self::WINDOW; $step <= $now + self::WINDOW; ++$step) {
            if (hash_equals(self::code($secret, $step), $typed) && $step > $lastStep && $accepted === null) {
                $accepted = $step;
            }
        }

        return $accepted;
    }

    /**
     * The address that the QR code holds and that an app reads (the "Key URI format" of the authenticator apps).
     *
     * @param string $host what tells this installation from another in the list of the app (a host name); only a label
     */
    public static function uri(string $secret, string $account, string $host = ''): string
    {
        $issuer = 'ThreadSafe' . ($host !== '' ? " ($host)" : '');

        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . rawurlencode($secret) . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }
}
