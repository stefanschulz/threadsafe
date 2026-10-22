<?php

declare(strict_types=1);

namespace ThreadSafe\Restore;

use ThreadSafe\Archive\Entry;
use ThreadSafe\Archive\ZipException;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Backup\BackupName;
use ThreadSafe\Backup\Manifest;
use ThreadSafe\Backup\ManifestException;
use ThreadSafe\Backup\PartMarker;
use ThreadSafe\Core\ExitCode;
use ThreadSafe\Crypto\CryptoException;
use ThreadSafe\Crypto\Unlock;

/**
 * A backup as it lies on disk, found from any one of its archive parts: the parts in order and the manifest from the last one.
 * Needs nothing of ThreadSafe's installation (no profile, no sidecar file), so a restore tool on a bare host can use it.
 *
 * The parts of an encrypted backup (`.zip.age`) are read through the decrypting stream, which needs the secret key or the passphrase
 * ({@see Unlock}); the part list then names the encrypted files, and {@see Parts} opens them.
 */
final readonly class BackupSource
{
    /**
     * @param list<string> $parts absolute paths, in the order of the backup
     */
    private function __construct(public array $parts, public Manifest $manifest, public ?Unlock $unlock = null)
    {
    }

    /**
     * @param string      $path   any part of the backup (`<profile>_<time>_<random>.zip` or `...-pNN.zip`, or the same with `.age` at the end)
     * @param Unlock|null $unlock what opens an encrypted backup (ignored for a plain one)
     *
     * @throws RestoreException the file is no part of a backup, a part is missing, a part does not belong to the backup, or an encrypted backup cannot be opened with what was given
     */
    public static function open(string $path, ?Unlock $unlock = null): self
    {
        $real = is_file($path) ? realpath($path) : false;
        if ($real === false) {
            throw new RestoreException("The archive \"$path\" does not exist.", ExitCode::GuardFailed);
        }
        $directory = dirname($real);
        $info = BackupName::fromFile(basename($real));
        if ($info === null || !in_array($info['extension'], ['zip', 'zip.age'], true)) {
            throw new RestoreException('"' . basename($real) . '" is not a part of a ThreadSafe backup (the name should look like <profile>_<date>-<time>_<code>.zip or ...-p01.zip, with .age at the end if it is encrypted).', ExitCode::GuardFailed);
        }
        $extension = $info['extension'];
        if ($extension === 'zip.age') {
            if (!function_exists('sodium_crypto_aead_chacha20poly1305_ietf_decrypt')) {
                throw new RestoreException('"' . basename($real) . '" is encrypted, and this PHP has no sodium extension (ext-sodium), which opening it needs. Ask the host to enable it, or decrypt the parts elsewhere with the age tool (age --decrypt -i KEYFILE).', ExitCode::GuardFailed);
            }
            if ($unlock === null) {
                throw new RestoreException('"' . basename($real) . '" is encrypted. Give the secret key (a key file) or the passphrase it was made for; without it nobody can open it.', ExitCode::GuardFailed);
            }
        } else {
            $unlock = null;
        }
        $base = $info['name']->base();

        // the parts of this backup that are in the directory; the last one has the manifest
        $found = [];
        foreach (scandir($directory) ?: [] as $file) {
            $other = BackupName::fromFile($file);
            if ($other !== null && $other['extension'] === $extension && $other['name']->base() === $base) {
                $found[$other['part'] ?? 0] = $directory . '/' . $file;
            }
        }
        ksort($found);
        $last = end($found);
        if ($last === false) {
            throw new RestoreException("No part of the backup \"$base\" found in \"$directory\".", ExitCode::GuardFailed);
        }

        $address = self::address($last, $unlock);
        try {
            $manifest = Manifest::fromArchive($address);
        } catch (ManifestException $e) {
            throw new RestoreException('The last part of the backup (' . basename($last) . ') cannot be used: ' . (Parts::failure($address) ?? $e->getMessage()) . ($unlock === null ? ' Is a later part missing?' : ''), ExitCode::GuardFailed, $e);
        } finally {
            Parts::release($address);
        }
        if ($manifest->backupId !== $base) {
            throw new RestoreException('The manifest in ' . basename($last) . " belongs to the backup \"{$manifest->backupId}\", not to \"$base\".", ExitCode::GuardFailed);
        }

        $parts = [];
        $missing = [];
        foreach ($manifest->parts as $part) {
            $file = $directory . '/' . $part['file'];
            is_file($file) ? $parts[] = $file : $missing[] = $part['file'];
        }
        if ($missing !== []) {
            throw new RestoreException('The backup consists of ' . count($manifest->parts) . ' part(s); missing in "' . $directory . '": ' . implode(', ', $missing) . '.', ExitCode::GuardFailed);
        }

        foreach ($parts as $index => $file) {
            self::checkMarker($file, $base, $index + 1, $unlock);
        }

        return new self($parts, $manifest, $unlock);
    }

    /**
     * @throws RestoreException the saved key is not valid
     */
    private static function address(string $part, ?Unlock $unlock): string
    {
        try {
            return Parts::address($part, $unlock);
        } catch (CryptoException $e) {
            throw new RestoreException($e->getMessage(), ExitCode::GuardFailed, $e);
        }
    }

    /**
     * @throws RestoreException
     */
    private static function checkMarker(string $file, string $base, int $index, ?Unlock $unlock): void
    {
        $address = self::address($file, $unlock);
        try {
            $reader = ZipReader::open($address);
            $entry = $reader->find(PartMarker::ENTRY);
            if (!$entry instanceof Entry) {
                throw new RestoreException(basename($file) . ' has no part marker: it is not a part written by ThreadSafe.', ExitCode::GuardFailed);
            }
            $marker = PartMarker::fromJson($reader->contents($entry, 65536));
        } catch (ZipException | ManifestException $e) {
            throw new RestoreException(basename($file) . ' cannot be read: ' . (Parts::failure($address) ?? $e->getMessage()), ExitCode::GuardFailed, $e);
        } finally {
            Parts::release($address);
        }
        if ($marker->backupId !== $base || $marker->index !== $index) {
            throw new RestoreException(basename($file) . " says it is part {$marker->index} of \"{$marker->backupId}\"; it should be part $index of \"$base\".", ExitCode::GuardFailed);
        }
    }
}
