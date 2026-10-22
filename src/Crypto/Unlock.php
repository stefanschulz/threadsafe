<?php

declare(strict_types=1);

namespace ThreadSafe\Crypto;

/**
 * What a restore is given to open an encrypted backup: secret keys or a passphrase, as plain data. The restore keeps it in the state of
 * its run (a file with mode 0600, like the database password) from tick to tick and blanks it at the end; the identities that the
 * decrypting stream needs are made from it each time a part is opened.
 */
final readonly class Unlock
{
    /**
     * @param list<string> $secretKeys
     */
    private function __construct(private array $secretKeys, private ?string $passphrase)
    {
    }

    /**
     * @param list<string> $secretKeys keys as `AGE-SECRET-KEY-1...`
     *
     * @throws CryptoException a key is not a secret key of the `age` format
     */
    public static function keys(array $secretKeys): self
    {
        foreach ($secretKeys as $key) {
            AgeKeys::secretBytes($key);
        }

        return new self($secretKeys, null);
    }

    public static function passphrase(string $passphrase): self
    {
        return new self([], $passphrase);
    }

    /**
     * From what a person typed or pasted: the text of a key file, a single secret key, or else a passphrase.
     *
     * @throws CryptoException the text starts like a secret key but is not a valid one, or it is empty
     */
    public static function fromText(string $text): self
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            throw CryptoException::malformed('There is no key or passphrase.');
        }
        foreach (preg_split('/\r?\n/', $trimmed) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                if (stripos($line, 'AGE-SECRET-KEY-1') === 0) {
                    return self::keys(AgeKeys::secretKeysIn($trimmed));
                }
                break;
            }
        }

        return self::passphrase(rtrim($text, "\r\n"));
    }

    /**
     * @return array{secretKeys: list<string>, passphrase: string|null}
     */
    public function toArray(): array
    {
        return ['secretKeys' => $this->secretKeys, 'passphrase' => $this->passphrase];
    }

    /**
     * @throws CryptoException the data is not what {@see toArray()} makes
     */
    public static function fromArray(mixed $data): ?self
    {
        if ($data === null) {
            return null;
        }
        if (!is_array($data) || !is_array($data['secretKeys'] ?? null) || !(is_string($data['passphrase'] ?? null) || ($data['passphrase'] ?? null) === null)) {
            throw CryptoException::malformed('The saved key of the restore is not valid.');
        }
        $keys = array_values(array_filter($data['secretKeys'], 'is_string'));
        if ($keys === [] && ($data['passphrase'] ?? '') === '') {
            return null;   // blanked at the end of a run
        }

        return new self($keys, $data['passphrase'] ?? null);
    }

    /**
     * @return list<Identity>
     */
    public function identities(): array
    {
        if ($this->passphrase !== null) {
            return [new PassphraseIdentity($this->passphrase)];
        }

        return array_map(static fn (string $key): Identity => new X25519Identity($key), $this->secretKeys);
    }
}
