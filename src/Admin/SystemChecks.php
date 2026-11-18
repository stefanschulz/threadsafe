<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use ThreadSafe\Auth\CoseKey;
use ThreadSafe\Auth\PasskeyStoreException;
use ThreadSafe\Auth\TwoFactorException;
use ThreadSafe\Core\Environment;
use ThreadSafe\Profile\Check;
use ThreadSafe\Profile\ProfileStore;

/**
 * The installation check of the System page (docs/PHASE4.md 3.9): what this server, this PHP and this installation allow, and what is
 * exposed that should not be. The checks themselves are {@see Environment}, which `threadsafe doctor`, the installer and the update use
 * too. This looks and reports; it changes nothing except the probe file for the question whether `data/` can be fetched.
 */
final class SystemChecks
{
    /** What the probe file holds; the page's script fetches the file from where `data/` would be served and compares. */
    public const string PROBE = 'threadsafe-data-probe';

    public function __construct(private readonly Services $services)
    {
    }

    /**
     * @return list<Check>
     */
    public function run(bool $https, bool $local): array
    {
        $runtime = Environment::runtime();
        // the page's own connection comes after the facts about PHP and before the process, as it always did
        $process = array_values(array_filter($runtime, static fn (Check $check): bool => $check->subject === 'Process'));
        $php = array_values(array_filter($runtime, static fn (Check $check): bool => $check->subject !== 'Process'));

        return [
            ...$php,
            Environment::connection($https, $local),
            $this->secondFactor(),
            $this->passkeys(),
            ...$process,
            ...Environment::installation(),
            ...Environment::dataDirectory($this->services->data),
            ...Environment::profiles($this->services->data),
        ];
    }

    /**
     * The second factor of the login: on, off (a warning: the login is one password) or a file that cannot be read. A passkey login has two
     * factors in one step, but the password still logs in alone until the second factor is on or `web.requireTwoFactor` stops it
     * (docs/PHASE14.md 3.5).
     */
    private function secondFactor(): Check
    {
        try {
            if ($this->services->twoFactor->isOn(AdminStore::DEFAULT_USER)) {
                return Check::ok('Second factor', 'The login asks for a code from an authenticator app after the password.');
            }
        } catch (TwoFactorException $e) {
            return Check::failure('Second factor', $e->getMessage() . ' The login is refused until it is repaired (Security page).');
        }
        try {
            $passkeys = $this->services->passkeys->count();
        } catch (PasskeyStoreException) {
            $passkeys = 0; // the row of the passkeys says what is wrong
        }
        if ($passkeys > 0 && $this->services->config->requireTwoFactor()) {
            return Check::ok('Second factor', 'Required for every login: a passkey logs in with two factors, and a password alone reaches only the Security page.');
        }
        if ($passkeys > 0) {
            return Check::warning('Second factor', 'A login with a passkey has two factors, but the password alone still logs in. Set up an authenticator app under Security, or switch on "Require the second factor for every login" under System.');
        }

        return Check::warning('Second factor', 'The login has only a password. Set up a second factor under Security: a code from an app on your phone, or a passkey, then belongs to it.');
    }

    /** The passkeys: how many, where they are bound, and why they cannot be used here if that is so. A file that cannot be read never blocks the password. */
    private function passkeys(): Check
    {
        $store = $this->services->passkeys;
        try {
            $count = $store->count();
            $rpId = $store->rpId();
        } catch (PasskeyStoreException $e) {
            return Check::warning('Passkeys', $e->getMessage() . ' The password login is not affected; "php bin/threadsafe admin passkey remove-all" removes the file.');
        }
        $algorithms = CoseKey::supported();
        if ($algorithms === []) {
            return Check::warning('Passkeys', 'This PHP has neither the openssl nor the sodium extension, so passkeys cannot be checked and are not offered. Ask your host to enable openssl.');
        }
        $missing = array_diff([CoseKey::ES256, CoseKey::EDDSA, CoseKey::RS256], $algorithms);
        $note = $missing === [] ? '' : ' (the extension for ' . (in_array(CoseKey::EDDSA, $missing, true) ? 'sodium' : 'openssl') . ' is missing: keys of that kind cannot be used)';
        if ($count === 0) {
            return Check::info('Passkeys', 'None registered. A passkey logs in with a fingerprint, face or screen lock; it is added under Security' . $note . '.');
        }

        return Check::ok('Passkeys', $count . ($count === 1 ? ' passkey' : ' passkeys') . ' bound to ' . $rpId . '; they work only on this host name, so a move to another one needs new ones' . $note . '.');
    }

    /**
     * Writes the file the browser is asked for to find out whether `data/` can be fetched from outside. Where it would be served
     * is not known to PHP (it depends on the document root), so the page's script asks for the file at the addresses where it would
     * be if the program directory lay below the web root.
     *
     * @return bool the file is there
     */
    public function writeProbe(): bool
    {
        $file = $this->services->data . '/probe.txt';
        if (!is_file($file) || trim((string) @file_get_contents($file)) !== self::PROBE) {
            @file_put_contents($file, self::PROBE . "\n");
        }

        return is_file($file);
    }

    /**
     * The storage directories of the profiles that lie inside a directory (the one `index.php` is in: the document root, or below it). A backup there is
     * served by a web server that does not read the `.htaccess` of the storage (nginx), unless a rule of its own closes it.
     *
     * @return list<string> the directories as they are on the disk, without a trailing slash
     */
    public function storagesBelow(string $scriptDirectory): array
    {
        $root = $scriptDirectory === '' ? false : realpath($scriptDirectory);
        if ($root === false) {
            return [];
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $found = [];
        foreach ((new ProfileStore($this->services->data . '/profiles'))->all() as $profile) {
            $real = is_dir($profile->storage) ? realpath($profile->storage) : false;
            if ($real !== false && str_starts_with(str_replace('\\', '/', $real) . '/', $root . '/')) {
                $found[] = rtrim(str_replace('\\', '/', $real), '/');
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Writes the file the browser is asked for into each storage directory, as {@see writeProbe()} does for `data/`.
     *
     * @param list<string> $storages
     *
     * @return list<string> the directories that have the file now
     */
    public function writeStorageProbes(array $storages): array
    {
        $written = [];
        foreach ($storages as $storage) {
            $file = $storage . '/probe.txt';
            if (!is_file($file) || trim((string) @file_get_contents($file)) !== self::PROBE) {
                @file_put_contents($file, self::PROBE . "\n");
            }
            if (is_file($file)) {
                $written[] = $storage;
            }
        }

        return $written;
    }
}
