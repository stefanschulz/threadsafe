<?php

declare(strict_types=1);

namespace ThreadSafe\Auth;

use Closure;
use JsonException;

/**
 * The passkeys of the administrator, `data/passkeys.json` (mode 0600; docs/PHASE14.md 3.1 and 3.4): the relying party id that they are bound to
 * (fixed by the first one), the user handle that every passkey was made with, and for each passkey its id, its public key, its counter, the
 * flags that say whether it is synced, a name and the dates. It holds public data only: a copy of the file lets nobody in.
 *
 * It is a file of its own, as `two-factor.json` is, so that the password and the second factor cannot lose it. No file means no passkeys. A file
 * that exists and cannot be read or does not make sense throws {@see PasskeyStoreException}; the caller turns that into "no passkey login
 * for now", never into a refused password login. What reads and then writes holds a lock on `passkeys.json.lock` for the whole of it.
 */
final class PasskeyStore
{
    public const int MAX_PASSKEYS = 10;
    public const int MAX_NAME = 64;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param Closure(): int|null $clock for tests: the time in seconds
     */
    public function __construct(private readonly string $file, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function fileExists(): bool
    {
        return is_file($this->file);
    }

    /**
     * @throws PasskeyStoreException
     */
    public function count(): int
    {
        return count($this->read()['passkeys']);
    }

    /**
     * The relying party id the passkeys are bound to, or null while there are none.
     *
     * @throws PasskeyStoreException
     */
    public function rpId(): ?string
    {
        return $this->read()['rpId'];
    }

    /**
     * The user handle of the passkeys that exist, or 64 new random bytes if there are none (the first registration stores them).
     *
     * @throws PasskeyStoreException
     */
    public function userHandle(): string
    {
        return $this->read()['userHandle'] ?? random_bytes(64);
    }

    /**
     * @return list<StoredPasskey>
     *
     * @throws PasskeyStoreException
     */
    public function all(): array
    {
        return $this->read()['passkeys'];
    }

    /**
     * @return list<PasskeyCredential>
     *
     * @throws PasskeyStoreException
     */
    public function credentials(): array
    {
        return array_map(static fn (StoredPasskey $p): PasskeyCredential => $p->credential, $this->read()['passkeys']);
    }

    /**
     * Adds a passkey that a registration made. The first one fixes the relying party id and the user handle; later ones must have the same.
     *
     * @throws PasskeyStoreException the file cannot be used, the id is there already, the limit is reached, or the host or the handle differ
     */
    public function add(string $user, string $rpId, string $userHandle, PasskeyCredential $credential, string $name): void
    {
        $this->locked(function () use ($user, $rpId, $userHandle, $credential, $name): void {
            $state = $this->read();
            if ($state['passkeys'] !== [] && ($state['rpId'] !== $rpId || $state['userHandle'] === null || !hash_equals($state['userHandle'], $userHandle))) {
                throw new PasskeyStoreException('The passkeys that exist are bound to another host name (' . ($state['rpId'] ?? '?') . ').');
            }
            if (count($state['passkeys']) >= self::MAX_PASSKEYS) {
                throw new PasskeyStoreException('There are ' . self::MAX_PASSKEYS . ' passkeys already; remove one first.');
            }
            foreach ($state['passkeys'] as $existing) {
                if (hash_equals($existing->credential->id, $credential->id)) {
                    throw new PasskeyStoreException('This passkey is stored already.');
                }
            }
            $state['passkeys'][] = new StoredPasskey($credential, $this->cleanName($name, count($state['passkeys']) + 1), $this->now(), null);
            $this->write($user, $rpId, $userHandle, $state['passkeys']);
        });
    }

    /**
     * Stores what a login changed: the counter, the state of the backup and the time of the use.
     *
     * @throws PasskeyStoreException
     */
    public function recordLogin(PasskeyCredential $after): void
    {
        $this->change($after->id, fn (StoredPasskey $p): StoredPasskey => new StoredPasskey($after, $p->name, $p->created, $this->now()));
    }

    /**
     * @return bool false if there is no such passkey
     *
     * @throws PasskeyStoreException
     */
    public function rename(string $id, string $name): bool
    {
        return $this->change($id, fn (StoredPasskey $p): StoredPasskey => new StoredPasskey($p->credential, $this->cleanName($name, 0), $p->created, $p->lastUsed));
    }

    /**
     * @return bool false if there is no such passkey
     *
     * @throws PasskeyStoreException
     */
    public function remove(string $id): bool
    {
        return $this->locked(function () use ($id): bool {
            $state = $this->read();
            $kept = array_values(array_filter($state['passkeys'], static fn (StoredPasskey $p): bool => !hash_equals($p->credential->id, $id)));
            if (count($kept) === count($state['passkeys'])) {
                return false;
            }
            $this->saveOrDelete($state, $kept);

            return true;
        });
    }

    /**
     * Removes every passkey (the file goes). A file that cannot be read is removed as well: it is what is in the way.
     *
     * @return int how many passkeys were there (0 if the file was not there or could not be read)
     *
     * @throws PasskeyStoreException the file cannot be removed
     */
    public function removeAll(): int
    {
        if (!is_file($this->file)) {
            return 0;
        }
        try {
            $count = $this->count();
        } catch (PasskeyStoreException) {
            $count = 0;
        }
        if (!@unlink($this->file) && is_file($this->file)) {
            throw new PasskeyStoreException('Cannot remove the file "' . $this->file . '". Delete it by hand.');
        }

        return $count;
    }

    /**
     * @param Closure(StoredPasskey): StoredPasskey $change
     */
    private function change(string $id, Closure $change): bool
    {
        return $this->locked(function () use ($id, $change): bool {
            $state = $this->read();
            $found = false;
            foreach ($state['passkeys'] as $i => $passkey) {
                if (hash_equals($passkey->credential->id, $id)) {
                    $state['passkeys'][$i] = $change($passkey);
                    $found = true;
                }
            }
            if ($found) {
                $this->write($state['user'], (string) $state['rpId'], (string) $state['userHandle'], $state['passkeys']);
            }

            return $found;
        });
    }

    /**
     * @param array{user: string, rpId: ?string, userHandle: ?string, passkeys: list<StoredPasskey>} $state
     * @param list<StoredPasskey>                                                                    $kept
     */
    private function saveOrDelete(array $state, array $kept): void
    {
        if ($kept === []) {
            // no passkey left: no file, so that "none" and "never had one" look the same
            if (!@unlink($this->file) && is_file($this->file)) {
                throw new PasskeyStoreException('Cannot remove the file of the passkeys "' . $this->file . '".');
            }

            return;
        }
        $this->write($state['user'], (string) $state['rpId'], (string) $state['userHandle'], $kept);
    }

    private function cleanName(string $name, int $number): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $name));
        if (mb_strlen($name) > self::MAX_NAME) {
            $name = trim(mb_substr($name, 0, self::MAX_NAME));
        }

        return $name !== '' ? $name : 'Passkey' . ($number > 0 ? ' ' . $number : '');
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', ($this->clock)());
    }

    /**
     * @return array{user: string, rpId: ?string, userHandle: ?string, passkeys: list<StoredPasskey>}
     *
     * @throws PasskeyStoreException
     */
    private function read(): array
    {
        $empty = ['user' => '', 'rpId' => null, 'userHandle' => null, 'passkeys' => []];
        if (!is_file($this->file)) {
            return $empty;
        }
        $content = @file_get_contents($this->file);
        if ($content === false) {
            throw new PasskeyStoreException('Cannot read the file of the passkeys "' . $this->file . '".');
        }
        try {
            $data = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PasskeyStoreException('The file of the passkeys is not valid JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($data) || !is_string($data['user'] ?? null) || !is_string($data['rpId'] ?? null) || $data['rpId'] === '' || !is_string($data['userHandle'] ?? null)
            || !is_array($data['passkeys'] ?? null) || !array_is_list($data['passkeys']) || $data['passkeys'] === [] || count($data['passkeys']) > self::MAX_PASSKEYS) {
            throw new PasskeyStoreException('The file of the passkeys does not have the form that this program writes.');
        }

        try {
            $handle = Base64Url::decode($data['userHandle']);
            $passkeys = [];
            foreach ($data['passkeys'] as $entry) {
                $passkeys[] = $this->entry($entry);
            }
        } catch (PasskeyException $e) {
            throw new PasskeyStoreException('The file of the passkeys holds an entry that cannot be used: ' . $e->getMessage(), 0, $e);
        }

        return ['user' => $data['user'], 'rpId' => $data['rpId'], 'userHandle' => $handle, 'passkeys' => $passkeys];
    }

    /**
     * @throws PasskeyException
     */
    private function entry(mixed $entry): StoredPasskey
    {
        if (!is_array($entry) || !is_string($entry['id'] ?? null) || !is_string($entry['key'] ?? null) || !is_string($entry['name'] ?? null)
            || !is_string($entry['created'] ?? null) || !is_int($entry['signCount'] ?? null) || $entry['signCount'] < 0
            || !is_bool($entry['backupEligible'] ?? null) || !is_bool($entry['backupState'] ?? null) || !is_string($entry['aaguid'] ?? null)) {
            throw new PasskeyException('an entry lacks a field or has one of the wrong kind');
        }
        $id = Base64Url::decode($entry['id']);
        if ($id === '' || strlen($id) > AuthenticatorData::MAX_CREDENTIAL_ID) {
            throw new PasskeyException('an entry has an impossible credential id');
        }
        $aaguid = Base64Url::decode($entry['aaguid']);
        if (strlen($aaguid) !== 16) {
            throw new PasskeyException('an entry has an AAGUID that is not 16 bytes');
        }
        $transports = [];
        foreach (is_array($entry['transports'] ?? null) ? $entry['transports'] : [] as $transport) {
            if (is_string($transport) && $transport !== '') {
                $transports[] = $transport;
            }
        }
        $lastUsed = $entry['lastUsed'] ?? null;
        if ($lastUsed !== null && !is_string($lastUsed)) {
            throw new PasskeyException('an entry has a time of use of the wrong kind');
        }

        return new StoredPasskey(
            new PasskeyCredential($id, CoseKey::fromCbor(Base64Url::decode($entry['key'])), $entry['signCount'], $entry['backupEligible'], $entry['backupState'], $aaguid, $transports),
            $entry['name'],
            $entry['created'],
            $lastUsed,
        );
    }

    /**
     * @param list<StoredPasskey> $passkeys
     *
     * @throws PasskeyStoreException
     */
    private function write(string $user, string $rpId, string $userHandle, array $passkeys): void
    {
        $list = [];
        foreach ($passkeys as $p) {
            $list[] = [
                'id' => Base64Url::encode($p->credential->id),
                'name' => $p->name,
                'key' => Base64Url::encode($p->credential->key->toCbor()),
                'signCount' => $p->credential->signCount,
                'backupEligible' => $p->credential->backupEligible,
                'backupState' => $p->credential->backupState,
                'aaguid' => Base64Url::encode($p->credential->aaguid),
                'transports' => $p->credential->transports,
                'created' => $p->created,
                'lastUsed' => $p->lastUsed,
            ];
        }
        $json = json_encode(['user' => $user, 'rpId' => $rpId, 'userHandle' => Base64Url::encode($userHandle), 'passkeys' => $list], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $temporary = $this->file . '.' . bin2hex(random_bytes(3)) . '.tmp';
        if ($json === false || @file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
            throw new PasskeyStoreException('Cannot write the file of the passkeys "' . $this->file . '".');
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $this->file)) {
            @unlink($temporary);

            throw new PasskeyStoreException('Cannot write the file of the passkeys "' . $this->file . '".');
        }
    }

    /**
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     *
     * @throws PasskeyStoreException the lock cannot be taken
     */
    private function locked(Closure $work): mixed
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new PasskeyStoreException("Cannot create the directory \"$directory\".");
        }
        $lock = @fopen($this->file . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new PasskeyStoreException('Cannot lock the file of the passkeys "' . $this->file . '".');
        }
        @chmod($this->file . '.lock', 0600);
        try {
            return $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
