<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

use Closure;

/**
 * Slows down guessing: after three attempts within a quarter of an hour the next attempt has to wait (5 s, doubling, up to
 * 5 minutes), and an attempt during the wait is not even compared. The attempts are kept in a small file, so the delay holds across
 * requests; one success wipes it. The count is not per address (an attacker changes addresses; the real owner then waits a few
 * minutes at worst).
 *
 * An attempt is **counted before it is checked**: {@see begin()} looks at the wait and notes the attempt in one step under a lock on the file, so
 * that requests which arrive together cannot all see "no wait yet" (30 parallel guesses against a fresh state were all compared when the
 * check and the note were two steps, docs/PHASE12.md section 8). A right key or password then calls {@see reset()}; a wrong one needs nothing more
 * than {@see failed()}, which only writes the log.
 *
 * Used by the key file of the single-file tools ({@see AccessKey}) and by the login, the second factor, the setup and the trigger address of the web interface.
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

    /**
     * Seconds until the next attempt is accepted; 0 if one is accepted now. It only looks (for a message or a status): an attempt starts with {@see begin()}.
     */
    public function waitSeconds(): int
    {
        return $this->locked(LOCK_SH, fn (array $attempts): array => [self::wait($attempts, ($this->clock)()), null]);
    }

    /**
     * Starts an attempt: if one is accepted now it is counted (and the caller checks the key, password or code), otherwise the seconds to wait are
     * returned and nothing is counted. Look and note are one step, so that parallel requests are counted one by one.
     *
     * @return int 0 if the attempt may go on, otherwise the seconds until the next attempt is accepted
     *
     * @phpstan-impure it changes the file, so the next call may answer otherwise
     */
    public function begin(): int
    {
        return $this->locked(LOCK_EX, function (array $attempts): array {
            $now = ($this->clock)();
            $wait = self::wait($attempts, $now);

            return [$wait, $wait > 0 ? null : [...$attempts, $now]];
        });
    }

    /**
     * Notes in the log that an attempt was wrong (it is counted already, by {@see begin()}).
     *
     * @param string $what what went wrong, for the log ("wrong key")
     */
    public function failed(string $address, string $what): void
    {
        if ($this->logFile === null) {
            return;
        }
        @file_put_contents($this->logFile, sprintf("%s %s from %s\n", gmdate('Y-m-d\TH:i:s\Z', ($this->clock)()), $what, preg_replace('/[^0-9a-fA-F:.]/', '?', $address)), FILE_APPEND | LOCK_EX);
        @chmod($this->logFile, 0600);
    }

    /** Forgets the attempts (after a success). */
    public function reset(): void
    {
        if (!is_file($this->attemptsFile)) {
            return;
        }
        $this->locked(LOCK_EX, static fn (array $attempts): array => [0, []]);
        @unlink($this->attemptsFile);
    }

    /**
     * @param list<int> $attempts times of the attempts within the window, oldest first
     */
    private static function wait(array $attempts, int $now): int
    {
        if (count($attempts) < self::FREE_ATTEMPTS) {
            return 0;
        }
        $delay = min(300, 5 * (2 ** (count($attempts) - self::FREE_ATTEMPTS)));

        return max(0, (int) end($attempts) + $delay - $now);
    }

    /**
     * Runs $work on the attempts of the window while the file is locked, and stores what it returns as the new attempts (null: no change).
     * If the file cannot be opened the work is done without a memory of earlier attempts, as before.
     *
     * @param int<0, 7>                                      $mode LOCK_SH or LOCK_EX
     * @param Closure(list<int>): array{int, list<int>|null} $work
     */
    private function locked(int $mode, Closure $work): int
    {
        $directory = dirname($this->attemptsFile);
        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }
        $mask = umask(0177);     // a file made here is private from the first moment
        $handle = $mode === LOCK_SH && !is_file($this->attemptsFile) ? false : @fopen($this->attemptsFile, 'c+');
        umask($mask);
        if ($handle === false || !flock($handle, $mode)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            return $work([])[0];
        }
        try {
            $data = json_decode((string) stream_get_contents($handle), true);
            $now = ($this->clock)();
            $attempts = array_values(array_filter(is_array($data) ? array_filter($data, 'is_int') : [], static fn (int $t): bool => $t > $now - self::WINDOW));
            [$result, $changed] = $work($attempts);
            if ($changed !== null) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, (string) json_encode($changed));
                fflush($handle);
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $result;
    }
}
