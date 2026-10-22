<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

use ThreadSafe\Crypto\AgeKeys;
use ThreadSafe\Crypto\CryptoException;
use ThreadSafe\Crypto\Identity;
use ThreadSafe\Crypto\PassphraseIdentity;
use ThreadSafe\Crypto\X25519Identity;

/**
 * The options by which a command is given the key or the passphrase that opens an encrypted backup: `--identity-file=FILE` (a key file as
 * `age-keygen` makes it) or `--passphrase-file=FILE`; the passphrase may also come from the environment variable THREADSAFE_PASSPHRASE.
 * A key or a passphrase is never an option value.
 */
final class KeyOptions
{
    /**
     * @return list<OptionSpec>
     */
    public static function specs(string $command): array
    {
        return [
            new OptionSpec('identity-file', "$command: a key file (as age-keygen makes it) that opens encrypted backups", takesValue: true, valueName: 'FILE'),
            new OptionSpec('passphrase-file', "$command: a file holding the passphrase of an encrypted backup (or set THREADSAFE_PASSPHRASE)", takesValue: true, valueName: 'FILE'),
        ];
    }

    /**
     * @return list<Identity>|null null if neither option nor the environment gives a key or passphrase
     *
     * @throws UsageError both options are given, or a file cannot be read
     * @throws CryptoException the file holds no key
     */
    public static function identities(Input $input): ?array
    {
        if ($input->has('identity-file') && $input->has('passphrase-file')) {
            throw new UsageError('Give --identity-file or --passphrase-file, not both.');
        }
        if ($input->has('identity-file')) {
            return array_map(static fn (string $key): Identity => new X25519Identity($key), AgeKeys::secretKeysIn(self::read((string) $input->value('identity-file'))));
        }
        if ($input->has('passphrase-file')) {
            return [new PassphraseIdentity(rtrim(self::read((string) $input->value('passphrase-file')), "\r\n"))];
        }
        $fromEnvironment = getenv('THREADSAFE_PASSPHRASE');

        return is_string($fromEnvironment) && $fromEnvironment !== '' ? [new PassphraseIdentity($fromEnvironment)] : null;
    }

    /**
     * The passphrase for a new backup, from `--passphrase-file` or THREADSAFE_PASSPHRASE; null if there is none.
     *
     * @throws UsageError a file cannot be read
     */
    public static function passphrase(Input $input): ?string
    {
        if ($input->has('passphrase-file')) {
            return rtrim(self::read((string) $input->value('passphrase-file')), "\r\n");
        }
        $fromEnvironment = getenv('THREADSAFE_PASSPHRASE');

        return is_string($fromEnvironment) && $fromEnvironment !== '' ? $fromEnvironment : null;
    }

    private static function read(string $file): string
    {
        $content = is_file($file) ? @file_get_contents($file) : false;
        if ($content === false) {
            throw new UsageError("Cannot read \"$file\".");
        }

        return $content;
    }

    private function __construct()
    {
    }
}
