<?php

declare(strict_types=1);

namespace ThreadSafe\Crypto;

use SodiumException;

/**
 * A secret key (`AGE-SECRET-KEY-1...`) as the thing that opens a file.
 */
final readonly class X25519Identity implements Identity
{
    /** @var string 32 bytes */
    private string $secret;

    /** @var string 32 bytes */
    private string $public;

    /**
     * @throws CryptoException the text is not a secret key of the `age` format
     */
    public function __construct(string $secretKey)
    {
        CryptoException::requireSodium();
        $this->secret = AgeKeys::secretBytes($secretKey);
        $this->public = sodium_crypto_scalarmult_base($this->secret);
    }

    public function unwrap(array $stanzas): ?string
    {
        foreach ($stanzas as $stanza) {
            if ($stanza->type !== 'X25519') {
                continue;
            }
            if (count($stanza->arguments) !== 1 || strlen($stanza->body) !== 32) {
                throw CryptoException::malformed('A key line of the file is malformed.');
            }
            $share = Base64::decode($stanza->arguments[0]);
            if (strlen($share) !== 32) {
                throw CryptoException::malformed('A key line of the file is malformed.');
            }
            try {
                $shared = sodium_crypto_scalarmult($this->secret, $share);
            } catch (SodiumException) {
                throw CryptoException::malformed('A key line of the file is malformed (its share is a point that gives no secret).');
            }
            $wrap = hash_hkdf('sha256', $shared, 32, 'age-encryption.org/v1/X25519', $share . $this->public);
            $fileKey = sodium_crypto_aead_chacha20poly1305_ietf_decrypt($stanza->body, '', str_repeat("\0", 12), $wrap);
            if ($fileKey !== false && strlen($fileKey) === 16) {
                return $fileKey;
            }
        }

        return null;
    }

    /** The matching public key, `age1...`. */
    public function recipient(): string
    {
        return Bech32::encode('age', $this->public);
    }
}
