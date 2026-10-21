<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

use Closure;

/**
 * The key that lets a person into the wizard (docs/PHASE3.md 3.2): a PHP file `restore-key.php` next to the tool that returns the
 * secret (`<?php return 'a secret you choose';`). Fetched over HTTP such a file executes and prints nothing, where a text file
 * would be served. Nothing works without it.
 *
 * Wrong keys are slowed down: after three failures within a quarter of an hour the next attempt has to wait (5 s, doubling, up
 * to 5 minutes) and an attempt during the wait is not even compared. Every failure is logged (time and address).
 */
final class AccessKey
{
    public const int MIN_LENGTH = 8;

    private const int WINDOW = 900;

    private const int FREE_ATTEMPTS = 3;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param Closure(): int|null $clock for tests: the time in seconds
     */
    public function __construct(private readonly string $keyFile, private readonly string $attemptsFile, private readonly string $logFile, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function exists(): bool
    {
        return is_file($this->keyFile);
    }

    /**
     * Why the key file cannot be used, or null if it can.
     */
    public function problem(): ?string
    {
        $key = $this->load();
        if ($key === null) {
            return 'The file ' . basename($this->keyFile) . ' does not return a text. It must contain <?php return \'a secret you choose\';';
        }
        if (strlen($key) < self::MIN_LENGTH) {
            return 'The secret in ' . basename($this->keyFile) . ' is too short: use at least ' . self::MIN_LENGTH . ' characters.';
        }

        return null;
    }

    /**
     * Checks a key given by a person.
     *
     * @return string|null null if it is right, otherwise a message for the person
     */
    public function check(string $given, string $address): ?string
    {
        $wait = $this->waitSeconds();
        if ($wait > 0) {
            return "Too many wrong keys. Wait $wait seconds before trying again.";
        }
        $key = $this->load();
        if ($key !== null && strlen($key) >= self::MIN_LENGTH && hash_equals($key, $given)) {
            @unlink($this->attemptsFile);

            return null;
        }

        $this->recordFailure($address);

        return 'This is not the key.';
    }

    /** Seconds until the next attempt is accepted; 0 if one is accepted now. */
    public function waitSeconds(): int
    {
        $failures = $this->failures();
        if (count($failures) < self::FREE_ATTEMPTS) {
            return 0;
        }
        $delay = min(300, 5 * (2 ** (count($failures) - self::FREE_ATTEMPTS)));

        return max(0, (int) end($failures) + $delay - ($this->clock)());
    }

    private function load(): ?string
    {
        if (!is_file($this->keyFile)) {
            return null;
        }
        ob_start();
        try {
            $key = (static function (string $file): mixed {
                return include $file;
            })($this->keyFile);
        } catch (\Throwable) {
            $key = null;
        } finally {
            ob_end_clean();
        }

        return is_string($key) ? $key : null;
    }

    /** @return list<int> times of the failures within the window, oldest first */
    private function failures(): array
    {
        $content = is_file($this->attemptsFile) ? @file_get_contents($this->attemptsFile) : false;
        $data = $content === false ? [] : json_decode($content, true);
        $now = ($this->clock)();

        return array_values(array_filter(is_array($data) ? array_filter($data, 'is_int') : [], static fn (int $t): bool => $t > $now - self::WINDOW));
    }

    private function recordFailure(string $address): void
    {
        $now = ($this->clock)();
        $failures = [...$this->failures(), $now];
        @file_put_contents($this->attemptsFile, json_encode($failures), LOCK_EX);
        @chmod($this->attemptsFile, 0600);
        @file_put_contents($this->logFile, sprintf("%s wrong key from %s\n", gmdate('Y-m-d\TH:i:s\Z', $now), preg_replace('/[^0-9a-fA-F:.]/', '?', $address)), FILE_APPEND | LOCK_EX);
        @chmod($this->logFile, 0600);
    }
}
