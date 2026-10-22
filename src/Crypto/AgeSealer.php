<?php

declare(strict_types=1);

namespace ThreadSafe\Crypto;

/**
 * Writes an `age` file (age-encryption.org/v1): the header with one stanza per recipient, a random 16 byte nonce, and the payload in
 * chunks of 64 KiB, each sealed with ChaCha20-Poly1305 under a key made from the file key and the nonce. The nonce of a chunk is its
 * number and a mark for the last chunk, so a chunk can be sealed on its own and a file that was cut short does not open.
 *
 * A sealer can be put away and taken up again ({@see state()}, {@see resume()}): the backup seals a part in ticks. What the state holds
 * is secret (the file key); it belongs in a file of mode 0600 that is deleted with the run.
 */
final class AgeSealer
{
    /** Bytes of plain text in a chunk (the last one may have less). */
    public const int CHUNK = 65536;

    /** Bytes the authentication tag adds to every chunk. */
    public const int TAG = 16;

    private readonly string $key;

    private function __construct(private readonly string $fileKey, private readonly string $nonce, private readonly string $header)
    {
        $this->key = hash_hkdf('sha256', $fileKey, 32, 'payload', $nonce);
    }

    /**
     * A new file: a new file key, wrapped for every recipient.
     *
     * @param list<Recipient> $recipients at least one; a passphrase must be the only one
     *
     * @throws CryptoException
     */
    public static function begin(array $recipients): self
    {
        CryptoException::requireSodium();
        if ($recipients === []) {
            throw CryptoException::malformed('A file needs at least one recipient (a public key or a passphrase).');
        }
        foreach ($recipients as $recipient) {
            if ($recipient->mustBeAlone() && count($recipients) > 1) {
                throw CryptoException::malformed('A passphrase cannot be combined with other keys in one file: the format of age does not allow it.');
            }
        }
        $fileKey = random_bytes(16);

        return new self($fileKey, random_bytes(16), AgeHeader::build(array_map(static fn (Recipient $r): Stanza => $r->wrap($fileKey), $recipients), $fileKey));
    }

    /**
     * Takes up a file that was begun earlier.
     *
     * @param array{fileKey: string, nonce: string, header: string} $state as {@see state()} gave it
     *
     * @throws CryptoException the state is not valid
     */
    public static function resume(array $state): self
    {
        CryptoException::requireSodium();
        $fileKey = self::unhex($state['fileKey']);
        $nonce = self::unhex($state['nonce']);
        $header = base64_decode($state['header'], true);
        if ($fileKey === null || strlen($fileKey) !== 16 || $nonce === null || strlen($nonce) !== 16 || $header === false || !str_starts_with($header, AgeHeader::MAGIC . "\n")) {
            throw CryptoException::malformed('The saved state of the encryption is not valid.');
        }

        return new self($fileKey, $nonce, $header);
    }

    /**
     * @return array{fileKey: string, nonce: string, header: string} secret: the file key is in it
     */
    public function state(): array
    {
        return ['fileKey' => bin2hex($this->fileKey), 'nonce' => bin2hex($this->nonce), 'header' => base64_encode($this->header)];
    }

    /** The bytes the file starts with: the header and the nonce. */
    public function head(): string
    {
        return $this->header . $this->nonce;
    }

    /**
     * Seals one chunk.
     *
     * @param string $plain at most {@see CHUNK} bytes; only the last chunk may have fewer
     * @param int    $index the number of the chunk, from 0
     * @param bool   $last  whether this is the last chunk of the file
     */
    public function seal(string $plain, int $index, bool $last): string
    {
        return sodium_crypto_aead_chacha20poly1305_ietf_encrypt($plain, '', self::nonce($index, $last), $this->key);
    }

    /**
     * Seals a whole stream.
     *
     * @param resource $in
     * @param resource $out
     *
     * @return int bytes written
     */
    public function sealStream($in, $out): int
    {
        $written = $this->put($out, $this->head());
        $index = 0;
        $current = (string) stream_get_contents($in, self::CHUNK);
        while (true) {
            // a chunk is the last one when nothing follows it; a full chunk at the end of the data needs a look at what comes next
            $next = strlen($current) < self::CHUNK ? '' : (string) stream_get_contents($in, self::CHUNK);
            $last = $next === '';
            $written += $this->put($out, $this->seal($current, $index, $last));
            if ($last) {
                return $written;
            }
            $current = $next;
            ++$index;
        }
    }

    /**
     * Encrypts a file.
     *
     * @param list<Recipient> $recipients
     *
     * @throws CryptoException
     */
    public static function encryptFile(string $source, string $target, array $recipients): void
    {
        $sealer = self::begin($recipients);
        $in = @fopen($source, 'rb');
        $out = @fopen($target, 'xb');
        if ($in === false || $out === false) {
            throw new CryptoException("Cannot open \"$source\" for reading or \"$target\" for writing (it must not exist).");
        }
        try {
            $sealer->sealStream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }
        @chmod($target, 0600);
    }

    /** The size of the sealed file for a plain file of this size and a head of this many bytes. */
    public static function sealedSize(int $plainSize, int $headLength): int
    {
        return $headLength + $plainSize + self::TAG * max(1, intdiv($plainSize + self::CHUNK - 1, self::CHUNK));
    }

    /** The nonce of a chunk: 11 bytes of its number, big endian, and one byte that marks the last chunk. */
    public static function nonce(int $index, bool $last): string
    {
        return "\0\0\0" . pack('J', $index) . ($last ? "\1" : "\0");
    }

    private static function unhex(string $text): ?string
    {
        return $text !== '' && strlen($text) % 2 === 0 && ctype_xdigit($text) ? (string) hex2bin($text) : null;
    }

    /**
     * @param resource $out
     */
    private function put($out, string $bytes): int
    {
        $length = strlen($bytes);
        $done = 0;
        while ($done < $length) {
            $n = fwrite($out, substr($bytes, $done));
            if ($n === false || $n === 0) {
                throw new CryptoException('Cannot write the encrypted file (disk full?).');
            }
            $done += $n;
        }

        return $length;
    }
}
