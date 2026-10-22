<?php

declare(strict_types=1);

namespace ThreadSafe\Crypto;

use SodiumException;

/**
 * A public key (`age1...`) as a recipient: the file key is wrapped with a key agreed from an ephemeral key pair and the public key
 * (X25519), as the format of `age` says.
 */
final readonly class X25519Recipient implements Recipient
{
    /** @var string 32 bytes */
    private string $public;

    /**
     * @param string $recipient `age1...`
     *
     * @throws CryptoException the text is not a public key of the `age` format
     */
    public function __construct(string $recipient)
    {
        CryptoException::requireSodium();
        $this->public = AgeKeys::publicBytes($recipient);
    }

    public function wrap(string $fileKey): Stanza
    {
        try {
            $ephemeral = random_bytes(32);
            $share = sodium_crypto_scalarmult_base($ephemeral);
            $shared = sodium_crypto_scalarmult($ephemeral, $this->public);
        } catch (SodiumException) {
            throw CryptoException::malformed('This public key cannot be used (it is a point that gives no secret).');
        }
        $wrap = hash_hkdf('sha256', $shared, 32, 'age-encryption.org/v1/X25519', $share . $this->public);

        return new Stanza('X25519', [Base64::encode($share)], sodium_crypto_aead_chacha20poly1305_ietf_encrypt($fileKey, '', str_repeat("\0", 12), $wrap));
    }

    public function mustBeAlone(): bool
    {
        return false;
    }
}
