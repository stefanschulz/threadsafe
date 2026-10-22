<?php

declare(strict_types=1);

namespace ThreadSafe\Crypto;

use ThreadSafe\Engine\Budget;

/**
 * A passphrase as the thing that opens a file. The file must have the single `scrypt` stanza of the format (a stanza among others does
 * not count: that is how `age` keeps a file for a passphrase from opening with anything else). A work factor that this host cannot
 * afford within its memory limit is refused with a message that says so, instead of running out of memory half way.
 */
final class PassphraseIdentity implements Identity
{
    /** @var array<string, string|null> the file keys found so far in this process, by a hash of passphrase and stanza */
    private static array $known = [];

    public function __construct(private readonly string $passphrase, private readonly ?int $memoryLimit = null)
    {
        CryptoException::requireSodium();
    }

    public function unwrap(array $stanzas): ?string
    {
        if (count($stanzas) !== 1 || $stanzas[0]->type !== 'scrypt') {
            return null;
        }
        $stanza = $stanzas[0];
        if (count($stanza->arguments) !== 2 || strlen($stanza->body) !== 32 || preg_match('/^[1-9][0-9]?$/', $stanza->arguments[1]) !== 1) {
            throw CryptoException::malformed('The passphrase line of the file is malformed.');
        }
        $salt = Base64::decode($stanza->arguments[0]);
        $logN = (int) $stanza->arguments[1];
        if (strlen($salt) !== 16 || $logN > 22) {
            throw CryptoException::malformed('The passphrase line of the file is malformed.');
        }
        // a ZIP reader opens a part twice and a restore opens it in every tick: the derivation (seconds of work) is done once per process
        $id = hash('sha256', $this->passphrase . "\0" . $salt . "\0" . $logN . "\0" . $stanza->body);
        if (array_key_exists($id, self::$known)) {
            return self::$known[$id];
        }
        $this->assertAffordable($logN);

        $key = Scrypt::derive($this->passphrase, 'age-encryption.org/v1/scrypt' . $salt, 1 << $logN, 8, 1, 32);
        $fileKey = sodium_crypto_aead_chacha20poly1305_ietf_decrypt($stanza->body, '', str_repeat("\0", 12), $key);
        sodium_memzero($key);
        if (count(self::$known) >= 16) {
            self::$known = [];
        }

        return self::$known[$id] = $fileKey !== false && strlen($fileKey) === 16 ? $fileKey : null;
    }

    /**
     * @throws CryptoException the work factor needs more memory than this PHP may use
     */
    private function assertAffordable(int $logN): void
    {
        $limit = $this->memoryLimit ?? Budget::parseBytes((string) ini_get('memory_limit'));
        if ($limit === null) {
            return;   // no limit
        }
        $needed = Scrypt::memoryFor($logN) + memory_get_usage();
        if ($needed > $limit) {
            throw CryptoException::tooExpensive(sprintf(
                'The passphrase of this file needs about %d MB of memory to check (work factor 2^%d, the usual one of the age tool is 2^18), and this PHP may use %d MB. Open it with the age tool (age -d) or with ThreadSafe on the command line with a higher memory limit (php -d memory_limit=512M).',
                (int) ceil($needed / 1048576),
                $logN,
                (int) floor($limit / 1048576),
            ));
        }
    }
}
