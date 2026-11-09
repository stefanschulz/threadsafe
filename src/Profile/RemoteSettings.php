<?php

declare(strict_types=1);

namespace ThreadSafe\Profile;

/**
 * The block `remote` of a profile (docs/PHASE13.md 3.3): the site is not on this machine, a ThreadSafe agent on it makes the backups and this machine
 * fetches them. The block is what the pairing string carries: the address of the agent and the secret that signs the requests.
 *
 * The secret is kept like a database password: in the profile file (mode 0600), never in a report, a listing or a log ({@see toArray()} masks it on
 * request). The class stands on its own, without the classes of the agent, because the profile is part of the restore tool, which does not carry them.
 */
final readonly class RemoteSettings
{
    /** The secret: 256 bits as hexadecimal digits (what the agent makes at pairing). */
    private const string SECRET_PATTERN = '/^[0-9a-f]{64}$/D';

    private const array KEYS = ['url', 'secret', 'plainHttp'];

    /**
     * @param string $url       the address of the agent, `https://host/path/threadsafe-backup.php`
     * @param string $secret    the secret of the pairing
     * @param bool   $plainHttp the person accepted that this machine talks to the agent over plain http (the requests are signed and the archives are
     *                          encrypted, but everything else is in the clear); the default is no
     */
    public function __construct(public string $url, public string $secret, public bool $plainHttp = false)
    {
    }

    /**
     * @throws ProfileException the data is not a valid block; the message says which field and why
     */
    public static function fromArray(mixed $data): ?self
    {
        if ($data === null) {
            return null;
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ProfileException('"remote" must be null or an object.');
        }
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new ProfileException("Unknown field \"$key\" in \"remote\" (known: " . implode(', ', self::KEYS) . ').');
            }
        }
        $url = $data['url'] ?? null;
        $secret = $data['secret'] ?? null;
        if (!is_string($url) || self::problem($url) !== null) {
            throw new ProfileException('"remote.url" must be a web address (https://host/path/threadsafe-backup.php)' . (is_string($url) && ($p = self::problem($url)) !== null ? ': ' . $p : '') . '.');
        }
        if (!is_string($secret) || preg_match(self::SECRET_PATTERN, $secret) !== 1) {
            throw new ProfileException('"remote.secret" must be 64 hexadecimal digits (the secret of the pairing).');
        }
        $plain = $data['plainHttp'] ?? false;
        if (!is_bool($plain)) {
            throw new ProfileException('"remote.plainHttp" must be true or false.');
        }
        $settings = new self($url, $secret, $plain);
        if (!$settings->isHttps() && !$plain) {
            throw new ProfileException('"remote.url" is a plain http address: the requests are signed and the archives encrypted, but everything else travels in the clear. Use https, or set "remote.plainHttp" to true to accept it.');
        }

        return $settings;
    }

    /** Why `$url` cannot be the address of an agent, or null. */
    public static function problem(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !in_array($parts['scheme'] ?? '', ['https', 'http'], true) || ($parts['host'] ?? '') === '') {
            return 'it is not an http or https address with a host name';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'it holds a user name or a password; the secret of the pairing is the only secret';
        }
        if (isset($parts['fragment']) || isset($parts['query'])) {
            return 'it holds a query or a fragment; the address is the one of the tool, and the agent adds its own';
        }
        if (preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return 'it holds a space or a control character';
        }

        return null;
    }

    public function isHttps(): bool
    {
        return str_starts_with(strtolower($this->url), 'https://');
    }

    /** The first 64 bits of a hash of the secret (not the secret), as the agent shows it: to compare on both sides. */
    public function fingerprint(): string
    {
        return substr(hash('sha256', 'threadsafe-agent-fingerprint ' . $this->secret), 0, 16);
    }

    /**
     * @param bool $withSecret false for everything that is shown to a person
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $withSecret = true): array
    {
        return ['url' => $this->url, 'secret' => $withSecret ? $this->secret : '********', 'plainHttp' => $this->plainHttp];
    }
}
