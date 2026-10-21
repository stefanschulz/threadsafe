<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use JsonException;

/**
 * The administrator of the web interface, `data/admin.json` (mode 0600): the password is kept only as a hash, Argon2id where
 * PHP has it, bcrypt otherwise. The file holds a list of users so that roles can be added later without a new format; the code
 * knows one user, {@see DEFAULT_USER}.
 */
final class AdminStore
{
    public const string DEFAULT_USER = 'admin';

    public const int MIN_PASSWORD_LENGTH = 10;

    /** Hashes of passwords nobody knows, verified against when the user does not exist so that "no such user" takes as long as "wrong password". */
    private const string DUMMY_ARGON2ID = '$argon2id$v=19$m=65536,t=4,p=1$d0hzckxxR0M1NFJzZG43Qw$SMxfeRnHLjyCMeiS0DhSvTmTg4ZzdKe3OeUg9GH9dz0';

    private const string DUMMY_BCRYPT = '$2y$12$E0wETFXNsF.EpIQLkcxOX.su2opE4.NwdQG4m9LmIoe6h4HRGjBzq';

    public function __construct(private readonly string $file)
    {
    }

    /** Whether an administrator has been set up. */
    public function exists(): bool
    {
        return $this->users() !== [];
    }

    /**
     * Checks a login. A hash made with weaker settings than today's default is renewed on success.
     */
    public function verify(string $user, string $password): bool
    {
        $users = $this->users();
        $hash = $users[$user]['hash'] ?? null;
        if ($hash === null) {
            password_verify($password, defined('PASSWORD_ARGON2ID') ? self::DUMMY_ARGON2ID : self::DUMMY_BCRYPT);

            return false;
        }
        if (!password_verify($password, $hash)) {
            return false;
        }
        if (password_needs_rehash($hash, self::algorithm())) {
            try {
                $this->setPassword($user, $password);
            } catch (ConfigException) {
                // a file that cannot be written must not keep the right password out
            }
        }

        return true;
    }

    /**
     * Sets or changes the password of a user.
     *
     * @throws ConfigException the password is not acceptable or the file cannot be written
     */
    public function setPassword(string $user, string $password): void
    {
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new ConfigException('The password must have at least ' . self::MIN_PASSWORD_LENGTH . ' characters.');
        }
        if (self::algorithm() === PASSWORD_BCRYPT && strlen($password) > 72) {
            throw new ConfigException('This PHP has no Argon2id; a bcrypt password can have at most 72 bytes.');
        }
        if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $user) !== 1) {
            throw new ConfigException('The user name must be 1-32 lower-case letters, digits, hyphens or underscores.');
        }

        $users = $this->users();
        $users[$user] = ['hash' => password_hash($password, self::algorithm()), 'changed' => gmdate('Y-m-d\TH:i:s\Z')];
        $list = [];
        foreach ($users as $name => $entry) {
            $list[] = ['name' => $name, 'hash' => $entry['hash'], 'changed' => $entry['changed']];
        }
        $this->write(['users' => $list]);
    }

    /** The name of the hash algorithm in use ("argon2id" or "bcrypt"), for the status display. */
    public static function algorithmName(): string
    {
        return self::algorithm() === PASSWORD_BCRYPT ? 'bcrypt' : 'argon2id';
    }

    private static function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    /**
     * @return array<string, array{hash: string, changed: string}>
     */
    private function users(): array
    {
        $content = is_file($this->file) ? @file_get_contents($this->file) : false;
        if ($content === false) {
            return [];
        }
        try {
            $data = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        $users = [];
        foreach (is_array($data) && is_array($data['users'] ?? null) ? $data['users'] : [] as $entry) {
            if (is_array($entry) && is_string($entry['name'] ?? null) && is_string($entry['hash'] ?? null) && $entry['hash'] !== '') {
                $users[$entry['name']] = ['hash' => $entry['hash'], 'changed' => is_string($entry['changed'] ?? null) ? $entry['changed'] : ''];
            }
        }

        return $users;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(array $data): void
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new ConfigException("Cannot create the directory \"$directory\".");
        }
        $temporary = $this->file . '.' . bin2hex(random_bytes(3)) . '.tmp';
        if (@file_put_contents($temporary, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) {
            throw new ConfigException('Cannot write the administrator file "' . $this->file . '".');
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $this->file)) {
            @unlink($temporary);

            throw new ConfigException('Cannot write the administrator file "' . $this->file . '".');
        }
    }
}
