<?php

declare(strict_types=1);

namespace ThreadSafe\Schedule;

/**
 * The secret in the address of the trigger, `/cron/<secret>` (docs/PHASE5.md 3.3). It lives in a file of its own in the data directory
 * (mode 0600), not in the configuration, which is shown on the System page and holds nothing secret. The address exists only while
 * there is a secret. The secret gives the right to start backups and nothing else, and `admin cron-secret` replaces it.
 */
final class CronSecret
{
    /** 32 random bytes as hexadecimal digits */
    private const string PATTERN = '/^[0-9a-f]{64}$/';

    public function __construct(private readonly string $file)
    {
    }

    public function exists(): bool
    {
        return $this->get() !== null;
    }

    public function get(): ?string
    {
        $content = is_file($this->file) ? @file_get_contents($this->file) : false;
        $secret = $content === false ? '' : trim($content);

        return preg_match(self::PATTERN, $secret) === 1 ? $secret : null;
    }

    /**
     * Makes a new secret (replacing the old one) and returns it.
     *
     * @throws ScheduleException the file cannot be written
     */
    public function make(): string
    {
        $secret = bin2hex(random_bytes(32));
        $directory = dirname($this->file);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new ScheduleException("Cannot create the directory \"$directory\".");
        }
        $temporary = $this->file . '.' . bin2hex(random_bytes(3)) . '.tmp';
        if (@file_put_contents($temporary, $secret . "\n", LOCK_EX) === false) {
            throw new ScheduleException("Cannot write the secret of the trigger to \"{$this->file}\".");
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $this->file)) {
            @unlink($temporary);

            throw new ScheduleException("Cannot write the secret of the trigger to \"{$this->file}\".");
        }

        return $secret;
    }

    /** Removes the secret; the address of the trigger is then gone. */
    public function remove(): void
    {
        @unlink($this->file);
    }

    /** Whether the text is the secret (compared in constant time; false while there is none). */
    public function matches(string $candidate): bool
    {
        $secret = $this->get();

        return $secret !== null && hash_equals($secret, $candidate);
    }
}
