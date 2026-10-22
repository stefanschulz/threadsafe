<?php

declare(strict_types=1);

namespace ThreadSafe\Restore;

use ThreadSafe\Archive\ZipException;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Crypto\AgeDecryptStream;
use ThreadSafe\Crypto\Unlock;

/**
 * How the restore opens the part of a backup: a plain part by its path, an encrypted one (`.zip.age`) through the decrypting stream,
 * which gives the ZIP reader the plain text of the part without a decrypted copy on disk. The address is only good in this process
 * and until it is released, so it is made whenever a part is opened and never saved.
 */
final class Parts
{
    public static function sealed(string $path): bool
    {
        return str_ends_with($path, '.zip.age');
    }

    /**
     * The address to open with {@see ZipReader::open()}: the path itself, or the address of the decrypting stream.
     *
     * @throws \ThreadSafe\Crypto\CryptoException the saved key is not valid, or this PHP has no ext-sodium
     */
    public static function address(string $path, ?Unlock $unlock): string
    {
        return self::sealed($path) && $unlock !== null ? AgeDecryptStream::url($path, $unlock->identities()) : $path;
    }

    /** @var list<string> the addresses that {@see open()} made and {@see releaseAll()} has not forgotten yet */
    private static array $made = [];

    /**
     * A reader on a part, plain or encrypted. A reader opens its file again whenever it walks the central directory, so the address of an
     * encrypted part stays good until {@see releaseAll()}: call that when the work with the readers is over.
     *
     * @throws ZipException the part cannot be read as a ZIP archive (for an encrypted one the message says why: no key of the file, damage ...)
     * @throws \ThreadSafe\Crypto\CryptoException the saved key is not valid
     */
    public static function open(string $path, ?Unlock $unlock): ZipReader
    {
        $address = self::address($path, $unlock);
        if ($address !== $path) {
            self::$made[] = $address;
        }
        try {
            return ZipReader::open($address);
        } catch (ZipException $e) {
            throw new ZipException(self::failure($address) ?? $e->getMessage(), 0, $e);
        }
    }

    /** Forgets the keys behind every address that {@see open()} made. */
    public static function releaseAll(): void
    {
        foreach (self::$made as $address) {
            self::release($address);
        }
        self::$made = [];
    }

    /** Forgets the keys behind an address made by {@see address()}. */
    public static function release(string $address): void
    {
        if (str_starts_with($address, AgeDecryptStream::SCHEME . '://')) {
            AgeDecryptStream::release($address);
        }
    }

    /** Why the last open of an encrypted part failed, in words for the person; null for a plain part. */
    public static function failure(string $address): ?string
    {
        return str_starts_with($address, AgeDecryptStream::SCHEME . '://') ? AgeDecryptStream::lastError() : null;
    }
}
