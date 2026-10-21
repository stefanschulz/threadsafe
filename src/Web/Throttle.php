<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

use Closure;

/**
 * Slows down guessing: after three failures within a quarter of an hour the next attempt has to wait (5 s, doubling, up to
 * 5 minutes), and an attempt during the wait is not even compared. The failures are kept in a small file, so the delay holds across
 * requests; one success wipes it. The count is not per address (an attacker changes addresses; the real owner then waits a few
 * minutes at worst).
 *
 * Used by the key file of the restore wizard ({@see AccessKey}) and by the login and the setup of the web interface.
 */
final class Throttle
{
    private const int WINDOW = 900;

    private const int FREE_ATTEMPTS = 3;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param string|null         $logFile where to note every failure (time, what, address); null = nowhere
     * @param Closure(): int|null $clock   for tests: the time in seconds
     */
    public function __construct(private readonly string $attemptsFile, private readonly ?string $logFile = null, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
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

    /**
     * @param string $what what went wrong, for the log ("wrong key")
     */
    public function fail(string $address, string $what): void
    {
        $now = ($this->clock)();
        $directory = dirname($this->attemptsFile);
        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }
        @file_put_contents($this->attemptsFile, json_encode([...$this->failures(), $now]), LOCK_EX);
        @chmod($this->attemptsFile, 0600);
        if ($this->logFile !== null) {
            @file_put_contents($this->logFile, sprintf("%s %s from %s\n", gmdate('Y-m-d\TH:i:s\Z', $now), $what, preg_replace('/[^0-9a-fA-F:.]/', '?', $address)), FILE_APPEND | LOCK_EX);
            @chmod($this->logFile, 0600);
        }
    }

    /** Forgets the failures (after a success). */
    public function reset(): void
    {
        @unlink($this->attemptsFile);
    }

    /** @return list<int> times of the failures within the window, oldest first */
    private function failures(): array
    {
        $content = is_file($this->attemptsFile) ? @file_get_contents($this->attemptsFile) : false;
        $data = $content === false ? [] : json_decode($content, true);
        $now = ($this->clock)();

        return array_values(array_filter(is_array($data) ? array_filter($data, 'is_int') : [], static fn (int $t): bool => $t > $now - self::WINDOW));
    }
}
