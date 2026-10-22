<?php

declare(strict_types=1);

namespace ThreadSafe\Crypto;

/**
 * A read-only, seekable PHP stream over an encrypted `age` file: `fread`, `fseek` and `ftell` work on the plain text, so that the ZIP
 * reader (and the extractor and the importer that use it) read an encrypted part without a decrypted copy on disk. The plain size comes
 * from the size of the file; only the chunks that are read are decrypted, one at a time.
 *
 * ```php
 * $url = AgeDecryptStream::url('/storage/shop_..._p01.zip.age', [new X25519Identity($secretKey)]);
 * $zip = ZipReader::open($url);   // or fopen($url, 'rb')
 * AgeDecryptStream::release($url);
 * ```
 *
 * The identities are kept in a registry under a random name that is part of the URL, because a stream wrapper is opened by name only
 * (and a ZIP reader opens the same name several times). A chunk that does not authenticate makes the read fail; {@see lastError()} says why.
 */
final class AgeDecryptStream
{
    public const string SCHEME = 'agedec';

    /** @var resource|null set by PHP */
    public $context;

    /** @var array<string, array{path: string, identities: list<Identity>}> */
    private static array $registry = [];

    private static ?string $lastError = null;

    /** @var resource|null */
    private $file;

    private ?AgeReader $reader = null;

    private int $position = 0;

    private int $cachedIndex = -1;

    private string $cached = '';

    /**
     * The address to open a file with: any function that opens a file (`fopen`, `ZipReader::open`) takes it.
     *
     * @param list<Identity> $identities
     */
    public static function url(string $path, array $identities): string
    {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
        $token = bin2hex(random_bytes(8));
        self::$registry[$token] = ['path' => $path, 'identities' => $identities];

        return self::SCHEME . '://' . $token;
    }

    /** Forgets the keys behind an address; it can not be opened again. */
    public static function release(string $url): void
    {
        unset(self::$registry[substr($url, strlen(self::SCHEME . '://'))]);
    }

    /** Why the last open or read failed, or null. */
    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$lastError = null;
        $entry = self::$registry[substr($path, strlen(self::SCHEME . '://'))] ?? null;
        if ($entry === null || str_contains($mode, 'w') || str_contains($mode, 'a') || str_contains($mode, '+')) {
            self::$lastError = $entry === null ? 'This address of an encrypted file is not valid any more.' : 'An encrypted file can only be read.';

            return false;
        }
        $file = @fopen($entry['path'], 'rb');
        if ($file === false) {
            self::$lastError = 'Cannot read "' . $entry['path'] . '".';

            return false;
        }
        try {
            $this->reader = AgeReader::open($file, $entry['identities']);
        } catch (CryptoException $e) {
            fclose($file);
            self::$lastError = $e->getMessage();

            return false;
        }
        $this->file = $file;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        $reader = $this->reader;
        if ($reader === null) {
            return false;
        }
        $out = '';
        while ($count > 0 && $this->position < $reader->size()) {
            $index = intdiv($this->position, AgeSealer::CHUNK);
            if ($index !== $this->cachedIndex) {
                try {
                    $this->cached = $reader->chunk($index);
                } catch (CryptoException $e) {
                    self::$lastError = $e->getMessage();

                    return $out === '' ? false : $out;
                }
                $this->cachedIndex = $index;
            }
            $piece = substr($this->cached, $this->position - $index * AgeSealer::CHUNK, $count);
            $out .= $piece;
            $this->position += strlen($piece);
            $count -= strlen($piece);
        }

        return $out;
    }

    public function stream_eof(): bool
    {
        return $this->reader === null || $this->position >= $this->reader->size();
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        $size = $this->reader?->size() ?? 0;
        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $size + $offset,
            default => -1,
        };
        if ($target < 0) {
            return false;
        }
        $this->position = $target;

        return true;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return ['size' => $this->reader?->size() ?? 0, 'mode' => 0100600];
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_close(): void
    {
        if ($this->file !== null) {
            fclose($this->file);
            $this->file = null;
        }
    }
}
