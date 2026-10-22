<?php

declare(strict_types=1);

namespace ThreadSafe\Crypto;

/**
 * A passphrase as a recipient: the standard `scrypt` stanza of the `age` format. The work factor is 2^15 by default (2.7 s and 42 MB
 * in PHP; the `age` tool itself uses 2^18 and opens a file with any factor up to 2^22). A file for a passphrase has this one stanza and
 * no other: otherwise it could also be opened with a weaker key.
 */
final readonly class ScryptRecipient implements Recipient
{
    public const int DEFAULT_LOG_N = 15;

    public function __construct(private string $passphrase, private int $logN = self::DEFAULT_LOG_N)
    {
        CryptoException::requireSodium();
        if ($passphrase === '') {
            throw CryptoException::malformed('The passphrase is empty.');
        }
        if ($logN < 10 || $logN > 22) {
            throw CryptoException::malformed('The work factor of a passphrase is 2^10 to 2^22.');
        }
    }

    public function wrap(string $fileKey): Stanza
    {
        $salt = random_bytes(16);
        $key = Scrypt::derive($this->passphrase, 'age-encryption.org/v1/scrypt' . $salt, 1 << $this->logN, 8, 1, 32);

        return new Stanza('scrypt', [Base64::encode($salt), (string) $this->logN], sodium_crypto_aead_chacha20poly1305_ietf_encrypt($fileKey, '', str_repeat("\0", 12), $key));
    }

    public function mustBeAlone(): bool
    {
        return true;
    }
}
