<?php

declare(strict_types=1);

namespace ThreadSafe\Crypto;

/**
 * Reads an `age` file with a secret key or a passphrase: finds the file key in the header, checks the header, and opens any chunk of
 * the payload on its own (the nonce of a chunk is its number). The size of the plain text follows from the size of the file, so a
 * reader can jump to the end without reading what lies before it.
 *
 * Every chunk is authenticated: a file that was cut short, extended, changed or damaged does not open, not even in one chunk, and
 * nothing is handed out before its tag has been checked.
 */
final class AgeReader
{
    private const int SEALED_CHUNK = AgeSealer::CHUNK + AgeSealer::TAG;

    private readonly string $key;

    private readonly int $chunks;

    private readonly int $size;

    /**
     * @param resource $handle
     */
    private function __construct(private $handle, string $fileKey, string $nonce, private readonly int $payloadOffset, int $fileSize)
    {
        $this->key = hash_hkdf('sha256', $fileKey, 32, 'payload', $nonce);
        $payload = $fileSize - $this->payloadOffset;
        if ($payload < AgeSealer::TAG) {
            throw CryptoException::damaged('The file ends before its data: it was cut off.');
        }
        $full = intdiv($payload, self::SEALED_CHUNK);
        $rest = $payload % self::SEALED_CHUNK;
        if ($rest !== 0 && $rest < AgeSealer::TAG) {
            throw CryptoException::damaged('The file ends inside the checksum of its last block: it was cut off.');
        }
        $this->chunks = $full + ($rest > 0 ? 1 : 0);
        $this->size = $full * AgeSealer::CHUNK + ($rest > 0 ? $rest - AgeSealer::TAG : 0);
        if ($this->chunks > 1 && $rest === AgeSealer::TAG) {
            throw CryptoException::damaged('The file has an empty last block after full ones: it was changed.');
        }
    }

    /**
     * Opens a file for reading.
     *
     * @param resource       $handle     a seekable stream positioned anywhere; the reader seeks as it needs
     * @param list<Identity> $identities the keys and passphrases to try
     *
     * @throws CryptoException not an `age` file, damaged, or none of the identities opens it
     */
    public static function open($handle, array $identities): self
    {
        CryptoException::requireSodium();
        if (@fseek($handle, 0) !== 0) {
            throw new CryptoException('The file cannot be read from the start.');
        }
        $data = '';
        while (!AgeHeader::isComplete($data)) {
            $more = fread($handle, 4096);
            if ($more === false || $more === '' || strlen($data) > AgeHeader::MAX_LENGTH) {
                throw CryptoException::malformed('This is not a file of the age format (no complete header).');
            }
            $data .= $more;
            if (strlen($data) >= strlen(AgeHeader::MAGIC) + 1 && !str_starts_with($data, AgeHeader::MAGIC . "\n")) {
                throw CryptoException::malformed('This is not a file of the age format (its first line is not "' . AgeHeader::MAGIC . '").');
            }
        }
        $header = AgeHeader::parse($data);
        foreach ($header->stanzas as $stanza) {
            if ($stanza->type === 'scrypt' && count($header->stanzas) > 1) {
                throw CryptoException::malformed('The header of the file combines a passphrase with other keys, which the age format does not allow.');
            }
        }

        $fileKey = null;
        foreach ($identities as $identity) {
            $fileKey = $identity->unwrap($header->stanzas);
            if ($fileKey !== null) {
                break;
            }
        }
        if ($fileKey === null) {
            throw CryptoException::noMatch($identities === [] ? 'No key or passphrase was given.' : 'The key or passphrase does not open this file.');
        }
        $header->verify($fileKey);

        fseek($handle, $header->length);
        $nonce = (string) fread($handle, 16);
        if (strlen($nonce) !== 16) {
            throw CryptoException::damaged('The file ends before its data: it was cut off.');
        }
        $stat = fstat($handle);
        if ($stat === false) {
            throw new CryptoException('The size of the file cannot be read.');
        }

        return new self($handle, $fileKey, $nonce, $header->length + 16, $stat['size']);
    }

    /** The size of the plain text in bytes. */
    public function size(): int
    {
        return $this->size;
    }

    public function chunkCount(): int
    {
        return $this->chunks;
    }

    /**
     * The plain text of one chunk.
     *
     * @throws CryptoException the chunk does not authenticate: the file was changed or is damaged, or it is cut short
     */
    public function chunk(int $index): string
    {
        if ($index < 0 || $index >= $this->chunks) {
            return '';
        }
        fseek($this->handle, $this->payloadOffset + $index * self::SEALED_CHUNK);
        $sealed = (string) fread($this->handle, self::SEALED_CHUNK);
        $plain = sodium_crypto_aead_chacha20poly1305_ietf_decrypt($sealed, '', AgeSealer::nonce($index, $index === $this->chunks - 1), $this->key);
        if ($plain === false) {
            throw CryptoException::damaged(sprintf('The data does not match its checksum at byte %d: the file was changed or is damaged.', $index * AgeSealer::CHUNK));
        }

        return $plain;
    }

    /**
     * Reads the whole file, checking every chunk.
     *
     * @param callable(string): void $sink gets the plain text, chunk by chunk
     *
     * @throws CryptoException
     */
    public function each(callable $sink): void
    {
        for ($i = 0; $i < $this->chunks; ++$i) {
            $sink($this->chunk($i));
        }
    }

    /**
     * Decrypts a file into another.
     *
     * @param list<Identity> $identities
     *
     * @throws CryptoException
     */
    public static function decryptFile(string $source, string $target, array $identities): void
    {
        $in = @fopen($source, 'rb');
        if ($in === false) {
            throw new CryptoException("Cannot read \"$source\".");
        }
        try {
            $reader = self::open($in, $identities);
            $out = @fopen($target, 'xb');
            if ($out === false) {
                throw new CryptoException("Cannot write \"$target\" (it must not exist).");
            }
            try {
                $reader->each(static function (string $plain) use ($out): void {
                    fwrite($out, $plain);
                });
            } finally {
                fclose($out);
            }
        } finally {
            fclose($in);
        }
        @chmod($target, 0600);
    }
}
