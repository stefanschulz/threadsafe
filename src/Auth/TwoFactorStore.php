<?php

declare(strict_types=1);

namespace ThreadSafe\Auth;

use Closure;
use JsonException;

/**
 * The second factor of the administrator, `data/two-factor.json` (mode 0600; docs/PHASE11.md 3.1): the secret of the authenticator
 * app (it has to be readable, so it cannot be a hash), the last step that was accepted, and the recovery codes as hashes. The file has
 * the list form of `admin.json`. It is a file of its own so that a change of the password, which writes `admin.json` new, cannot lose it.
 *
 * No file means no second factor. A file that exists and cannot be read or does not make sense is **not** that: every method that
 * reads it throws {@see TwoFactorException}, and the login refuses instead of letting a damaged file switch the protection off.
 *
 * What reads and then writes (a code is accepted once, a recovery code is struck out) holds a lock on `two-factor.json.lock` for the
 * whole of it, so that two requests with the same code cannot both succeed.
 */
final class TwoFactorStore
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param Closure(): int|null $clock for tests: the time in seconds
     */
    public function __construct(private readonly string $file, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** Whether the file is there at all (the command "disable" and the roll-back note ask this). */
    public function fileExists(): bool
    {
        return is_file($this->file);
    }

    /**
     * @throws TwoFactorException the file exists and cannot be read
     */
    public function isOn(string $user): bool
    {
        return isset($this->read()[$user]);
    }

    /**
     * The secret of a user in Base32, or null if the factor is off.
     *
     * @throws TwoFactorException the file exists and cannot be read
     */
    public function secret(string $user): ?string
    {
        return $this->read()[$user]['secret'] ?? null;
    }

    /**
     * When the factor was switched on (UTC, ISO 8601), or null if it is off.
     *
     * @throws TwoFactorException the file exists and cannot be read
     */
    public function enrolled(string $user): ?string
    {
        return $this->read()[$user]['enrolled'] ?? null;
    }

    /**
     * How many recovery codes are left.
     *
     * @throws TwoFactorException the file exists and cannot be read
     */
    public function recoveryLeft(string $user): int
    {
        return count($this->read()[$user]['recovery'] ?? []);
    }

    /**
     * Switches the factor on, or replaces it (a new secret, the step counter at zero).
     *
     * @param string       $secret        in Base32
     * @param list<string> $recoveryCodes as {@see RecoveryCodes::generate()} makes them; only their hashes are kept
     *
     * @throws TwoFactorException the secret is not a Base32 key, or the file cannot be written
     */
    public function enable(string $user, string $secret, array $recoveryCodes): void
    {
        $secret = strtoupper($secret);
        if (preg_match('/^[A-Z2-7]{16,}$/', $secret) !== 1) {
            throw new TwoFactorException('The secret of the second factor is not a Base32 key.');
        }
        $this->locked(function () use ($user, $secret, $recoveryCodes): void {
            $entries = $this->read();
            $entries[$user] = [
                'secret' => $secret,
                'enrolled' => gmdate('Y-m-d\TH:i:s\Z', ($this->clock)()),
                'lastStep' => 0,
                'recovery' => array_map(RecoveryCodes::hash(...), $recoveryCodes),
            ];
            $this->write($entries);
        });
    }

    /**
     * Replaces all recovery codes of a user.
     *
     * @param list<string> $recoveryCodes
     *
     * @throws TwoFactorException the factor is off, or the file cannot be written
     */
    public function replaceRecoveryCodes(string $user, array $recoveryCodes): void
    {
        $this->locked(function () use ($user, $recoveryCodes): void {
            $entries = $this->read();
            if (!isset($entries[$user])) {
                throw new TwoFactorException('The second factor is not switched on.');
            }
            $entries[$user]['recovery'] = array_map(RecoveryCodes::hash(...), $recoveryCodes);
            $this->write($entries);
        });
    }

    /**
     * Checks a code from the app. A right code is accepted once: the step it belongs to is kept, and a code of that step or an older one
     * is refused afterwards.
     *
     * @param int $time now, in seconds
     *
     * @throws TwoFactorException the file exists and cannot be read or written
     */
    public function acceptCode(string $user, string $typed, int $time): bool
    {
        return $this->locked(function () use ($user, $typed, $time): bool {
            $entries = $this->read();
            if (!isset($entries[$user])) {
                return false;
            }
            $step = Totp::verify($entries[$user]['secret'], $typed, $time, $entries[$user]['lastStep']);
            if ($step === null) {
                return false;
            }
            $entries[$user]['lastStep'] = $step;
            $this->write($entries);

            return true;
        });
    }

    /**
     * Checks a recovery code. A right one is struck out, so it works once.
     *
     * @throws TwoFactorException the file exists and cannot be read or written
     */
    public function useRecoveryCode(string $user, string $typed): bool
    {
        return $this->locked(function () use ($user, $typed): bool {
            $entries = $this->read();
            if (!isset($entries[$user])) {
                return false;
            }
            $hash = RecoveryCodes::hash($typed);
            $left = [];
            $found = false;
            // every stored hash is compared, so that the time taken does not tell which place the code had
            foreach ($entries[$user]['recovery'] as $stored) {
                if (hash_equals($stored, $hash) && !$found) {
                    $found = true;
                } else {
                    $left[] = $stored;
                }
            }
            if (!$found) {
                return false;
            }
            $entries[$user]['recovery'] = $left;
            $this->write($entries);

            return true;
        });
    }

    /**
     * Switches the factor off. When no user keeps one, the file is removed.
     *
     * @return bool whether it was on
     *
     * @throws TwoFactorException the file exists and cannot be read or written
     */
    public function disable(string $user): bool
    {
        return $this->locked(function () use ($user): bool {
            $entries = $this->read();
            if (!isset($entries[$user])) {
                return false;
            }
            unset($entries[$user]);
            if ($entries === []) {
                if (!@unlink($this->file) && is_file($this->file)) {
                    throw new TwoFactorException('Cannot remove the file of the second factor "' . $this->file . '".');
                }
            } else {
                $this->write($entries);
            }

            return true;
        });
    }

    /**
     * @return array<string, array{secret: string, enrolled: string, lastStep: int, recovery: list<string>}>
     *
     * @throws TwoFactorException the file exists and cannot be read or does not hold what this class writes
     */
    private function read(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $content = @file_get_contents($this->file);
        if ($content === false) {
            throw new TwoFactorException('Cannot read the file of the second factor "' . $this->file . '".');
        }
        try {
            $data = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new TwoFactorException('The file of the second factor is not valid JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($data) || !is_array($data['users'] ?? null) || !array_is_list($data['users'])) {
            throw new TwoFactorException('The file of the second factor does not hold a list of users.');
        }

        $entries = [];
        foreach ($data['users'] as $entry) {
            if (!is_array($entry) || !is_string($entry['name'] ?? null) || !is_string($entry['secret'] ?? null)
                || preg_match('/^[A-Z2-7]{16,}$/', $entry['secret']) !== 1) {
                throw new TwoFactorException('The file of the second factor holds a user without a name or a secret.');
            }
            $recovery = [];
            foreach (is_array($entry['recovery'] ?? null) ? $entry['recovery'] : [] as $hash) {
                if (is_string($hash) && preg_match('/^[0-9a-f]{64}$/', $hash) === 1) {
                    $recovery[] = $hash;
                }
            }
            $entries[$entry['name']] = [
                'secret' => $entry['secret'],
                'enrolled' => is_string($entry['enrolled'] ?? null) ? $entry['enrolled'] : '',
                'lastStep' => is_int($entry['lastStep'] ?? null) && $entry['lastStep'] > 0 ? $entry['lastStep'] : 0,
                'recovery' => $recovery,
            ];
        }

        return $entries;
    }

    /**
     * @param array<string, array{secret: string, enrolled: string, lastStep: int, recovery: list<string>}> $entries
     *
     * @throws TwoFactorException the file cannot be written
     */
    private function write(array $entries): void
    {
        $list = [];
        foreach ($entries as $name => $entry) {
            $list[] = ['name' => $name, ...$entry];
        }
        $temporary = $this->file . '.' . bin2hex(random_bytes(3)) . '.tmp';
        if (@file_put_contents($temporary, json_encode(['users' => $list], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) {
            throw new TwoFactorException('Cannot write the file of the second factor "' . $this->file . '".');
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $this->file)) {
            @unlink($temporary);

            throw new TwoFactorException('Cannot write the file of the second factor "' . $this->file . '".');
        }
    }

    /**
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     *
     * @throws TwoFactorException the lock cannot be taken
     */
    private function locked(Closure $work): mixed
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new TwoFactorException("Cannot create the directory \"$directory\".");
        }
        $lock = @fopen($this->file . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new TwoFactorException('Cannot lock the file of the second factor "' . $this->file . '".');
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
