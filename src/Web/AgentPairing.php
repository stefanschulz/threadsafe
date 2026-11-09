<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

use InvalidArgumentException;
use ThreadSafe\Profile\SiteProfile;

/**
 * The pairing string (docs/PHASE13.md 3.2): what a person copies from the host (the plugin page, the wizard, the shell) to the central ThreadSafe once,
 *
 * ```
 * ts1:<base64url of {"v":1,"url":"https://host/path/threadsafe-backup.php","secret":"<64 hex digits>","profile":"shop","fp":"<16 hex digits>"}>
 * ```
 *
 * `fp` is the fingerprint of the secret, shown on both sides: a string that was damaged in the clipboard fails its check at once, and two people can
 * compare the short figure. The secret is in the string, so the string is as secret as a password: shown once, kept in the profile file (mode 0600) on
 * the central side.
 */
final class AgentPairing
{
    public const string PREFIX = 'ts1:';

    /** The secret: 256 bits as hexadecimal digits. */
    public const string SECRET_PATTERN = '/^[0-9a-f]{64}$/D';

    public static function secret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** The first 64 bits of a hash of the secret (not the secret). */
    public static function fingerprint(string $secret): string
    {
        return substr(hash('sha256', 'threadsafe-agent-fingerprint ' . $secret), 0, 16);
    }

    /** `1a2b-3c4d-5e6f-7a8b` for people. */
    public static function readable(string $fingerprint): string
    {
        return implode('-', str_split($fingerprint, 4));
    }

    /**
     * @throws InvalidArgumentException the address, the secret or the profile id is not valid
     */
    public static function encode(string $url, string $secret, string $profile): string
    {
        self::check($url, $secret, $profile);
        $json = json_encode(['v' => 1, 'url' => $url, 'secret' => $secret, 'profile' => $profile, 'fp' => self::fingerprint($secret)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return self::PREFIX . rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array{url: string, secret: string, profile: string, fingerprint: string}
     *
     * @throws InvalidArgumentException not a pairing string, damaged, of another version, or with a value that is not valid
     */
    public static function decode(string $text): array
    {
        $text = trim($text);
        if (!str_starts_with($text, self::PREFIX)) {
            throw new InvalidArgumentException('This is not a pairing string (it starts with "' . self::PREFIX . '").');
        }
        $encoded = substr($text, strlen(self::PREFIX));
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1) {
            throw new InvalidArgumentException('The pairing string is damaged: it holds characters that do not belong to it.');
        }
        $json = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data) || !is_string($data['url'] ?? null) || !is_string($data['secret'] ?? null) || !is_string($data['profile'] ?? null) || !is_string($data['fp'] ?? null)) {
            throw new InvalidArgumentException('The pairing string is damaged: it cannot be read.');
        }
        if (($data['v'] ?? null) !== 1) {
            throw new InvalidArgumentException('The pairing string is of another version of the agent: update the side that is older.');
        }
        self::check($data['url'], $data['secret'], $data['profile']);
        if (!hash_equals(self::fingerprint($data['secret']), $data['fp'])) {
            throw new InvalidArgumentException('The pairing string is damaged: its fingerprint does not fit its secret. Copy it again.');
        }

        return ['url' => $data['url'], 'secret' => $data['secret'], 'profile' => $data['profile'], 'fingerprint' => $data['fp']];
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function check(string $url, string $secret, string $profile): void
    {
        $parts = parse_url($url);
        if ($parts === false || !in_array($parts['scheme'] ?? '', ['https', 'http'], true) || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('The address in the pairing string is not a web address (https://host/path).');
        }
        if (preg_match(self::SECRET_PATTERN, $secret) !== 1) {
            throw new InvalidArgumentException('The secret in the pairing string is not 64 hexadecimal digits.');
        }
        if (preg_match(SiteProfile::ID_PATTERN, $profile) !== 1) {
            throw new InvalidArgumentException('The profile id in the pairing string is not valid.');
        }
    }
}
