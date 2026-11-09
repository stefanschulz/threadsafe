<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

use InvalidArgumentException;
use RuntimeException;
use ThreadSafe\Profile\SiteProfile;

/**
 * What the agent of the backup tool knows about itself (docs/PHASE13.md 3.1, 3.2): whether it is switched on, the secret of the pairing, the id of the
 * central profile that its backups carry, the addresses that may ask (empty: all), how many backups it keeps after a verified pull, and whether it
 * answers a request that does not come over https. One file in the data directory of the workspace (`agent.json`, mode 0600, written whole and moved
 * into place), which only a person on the host can change (the page "Agent" of the wizard, the plugin, the shell): **the API never writes it**
 * (decision 2).
 *
 * **Off by default.** A file that is missing, damaged or without a secret is an agent that is off: {@see isOn()} is the one question the entry asks.
 */
final class AgentConfig
{
    /** The version of the API that this agent speaks; the pairing string and `status` carry it. */
    public const int API_VERSION = 1;

    public const string FILE = 'agent.json';

    /** @var array{enabled: bool, secret: string, profile: string, allow: list<string>, keepOnHost: int, plain: bool, paired: int} */
    private array $data;

    public function __construct(private readonly string $file)
    {
        $this->data = self::read($file);
    }

    public static function in(string $dataDirectory): self
    {
        return new self(rtrim($dataDirectory, '/\\') . '/' . self::FILE);
    }

    /** Whether the agent answers: switched on, and paired (a secret and a profile id). */
    public function isOn(): bool
    {
        return $this->data['enabled'] && $this->isPaired();
    }

    public function isPaired(): bool
    {
        return $this->data['secret'] !== '' && $this->data['profile'] !== '';
    }

    public function secret(): string
    {
        return $this->data['secret'];
    }

    /** The id of the central profile; the backups of the agent are made under it (decision 3). */
    public function profile(): string
    {
        return $this->data['profile'];
    }

    /** @return list<string> addresses and ranges (`203.0.113.7`, `198.51.100.0/24`, `2001:db8::/32`); empty: every address */
    public function allow(): array
    {
        return $this->data['allow'];
    }

    public function keepOnHost(): int
    {
        return $this->data['keepOnHost'];
    }

    /** Whether a request that does not come over https is answered (the owner ticked it; the default is no). */
    public function plainAllowed(): bool
    {
        return $this->data['plain'];
    }

    public function pairedAt(): int
    {
        return $this->data['paired'];
    }

    /**
     * Makes a new secret for the central profile `$profile` (the old pairing stops working) and returns it. The agent stays as switched as it was:
     * a person switches it on when the central side has the secret.
     *
     * @throws InvalidArgumentException the profile id is not valid
     * @throws RuntimeException         the file cannot be written
     */
    public function pair(string $profile, ?int $now = null): string
    {
        if (preg_match(SiteProfile::ID_PATTERN, $profile) !== 1) {
            throw new InvalidArgumentException("\"$profile\" is not a valid profile id (lower-case letters, digits, - and _; up to 40 characters).");
        }
        $this->data['secret'] = AgentPairing::secret();
        $this->data['profile'] = $profile;
        $this->data['paired'] = $now ?? time();
        $this->write();

        return $this->data['secret'];
    }

    public function enable(bool $on): void
    {
        $this->data['enabled'] = $on;
        $this->write();
    }

    /**
     * @param list<string> $allow
     *
     * @throws InvalidArgumentException an entry is no address and no range
     */
    public function setAllow(array $allow): void
    {
        $clean = [];
        foreach ($allow as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (!self::validEntry($entry)) {
                throw new InvalidArgumentException("\"$entry\" is no IP address and no range (203.0.113.7, 198.51.100.0/24, 2001:db8::/32).");
            }
            $clean[] = $entry;
        }
        $this->data['allow'] = array_values(array_unique($clean));
        $this->write();
    }

    /**
     * @throws InvalidArgumentException not from 0 to 20
     */
    public function setKeepOnHost(int $count): void
    {
        if ($count < 0 || $count > 20) {
            throw new InvalidArgumentException('The number of backups that the host keeps is from 0 to 20.');
        }
        $this->data['keepOnHost'] = $count;
        $this->write();
    }

    public function setPlainAllowed(bool $plain): void
    {
        $this->data['plain'] = $plain;
        $this->write();
    }

    /** Forgets the pairing and switches the agent off (the secret is gone: nothing that was signed with it works). */
    public function unpair(): void
    {
        $this->data['enabled'] = false;
        $this->data['secret'] = '';
        $this->data['profile'] = '';
        $this->data['paired'] = 0;
        $this->write();
    }

    /** Whether a request from `$address` may be answered, by the list of addresses (an empty list lets everybody ask). */
    public function allows(string $address): bool
    {
        if ($this->data['allow'] === []) {
            return true;
        }
        foreach ($this->data['allow'] as $entry) {
            if (self::matches($entry, $address)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------------------------------------- addresses

    private static function validEntry(string $entry): bool
    {
        $parts = explode('/', $entry, 2);
        $packed = @inet_pton($parts[0]);
        if ($packed === false) {
            return false;
        }
        if (!isset($parts[1])) {
            return true;
        }

        return preg_match('/^\d{1,3}$/D', $parts[1]) === 1 && (int) $parts[1] <= strlen($packed) * 8;
    }

    /** Whether `$address` is the address `$entry` or inside the range `$entry` (an address of the other family never is). */
    public static function matches(string $entry, string $address): bool
    {
        $parts = explode('/', $entry, 2);
        $net = @inet_pton($parts[0]);
        $ip = @inet_pton($address);
        if ($net === false || $ip === false || strlen($net) !== strlen($ip)) {
            return false;
        }
        $bits = isset($parts[1]) ? (int) $parts[1] : strlen($net) * 8;
        $whole = intdiv($bits, 8);
        if (substr($net, 0, $whole) !== substr($ip, 0, $whole)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($net[$whole]) & $mask) === (ord($ip[$whole]) & $mask);
    }

    // ------------------------------------------------------------------------------------------------- the file

    /**
     * @return array{enabled: bool, secret: string, profile: string, allow: list<string>, keepOnHost: int, plain: bool, paired: int}
     */
    private static function read(string $file): array
    {
        $default = ['enabled' => false, 'secret' => '', 'profile' => '', 'allow' => [], 'keepOnHost' => 1, 'plain' => false, 'paired' => 0];
        $text = is_file($file) ? @file_get_contents($file) : false;
        $data = $text === false ? null : json_decode($text, true);
        if (!is_array($data)) {
            return $default;
        }
        $secret = is_string($data['secret'] ?? null) && preg_match(AgentPairing::SECRET_PATTERN, $data['secret']) === 1 ? $data['secret'] : '';
        $profile = is_string($data['profile'] ?? null) && preg_match(SiteProfile::ID_PATTERN, $data['profile']) === 1 ? $data['profile'] : '';
        $allow = [];
        foreach (is_array($data['allow'] ?? null) ? $data['allow'] : [] as $entry) {
            if (is_string($entry) && self::validEntry($entry)) {
                $allow[] = $entry;
            }
        }
        $keep = is_int($data['keepOnHost'] ?? null) && $data['keepOnHost'] >= 0 && $data['keepOnHost'] <= 20 ? $data['keepOnHost'] : 1;

        return [
            'enabled' => ($data['enabled'] ?? false) === true && $secret !== '' && $profile !== '',
            'secret' => $secret,
            'profile' => $profile,
            'allow' => $allow,
            'keepOnHost' => $keep,
            'plain' => ($data['plain'] ?? false) === true,
            'paired' => is_int($data['paired'] ?? null) ? $data['paired'] : 0,
        ];
    }

    /**
     * @throws RuntimeException
     */
    private function write(): void
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create the directory \"$directory\".");
        }
        $temporary = $this->file . '.' . bin2hex(random_bytes(3)) . '.tmp';
        if (@file_put_contents($temporary, json_encode($this->data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false) {
            throw new RuntimeException("Cannot write the settings of the agent to \"{$this->file}\".");
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $this->file)) {
            @unlink($temporary);

            throw new RuntimeException("Cannot write the settings of the agent to \"{$this->file}\".");
        }
    }
}
