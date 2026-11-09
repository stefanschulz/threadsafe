<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

/**
 * The nonces of the requests that the agent has accepted in the last two windows of {@see AgentSignature} (a small file, locked while it is read
 * and written, one line per nonce: "expiry nonce"). A nonce that is in it is a request that was sent before: refused. What is older than the window can be
 * forgotten, because a request that old is refused by its time anyway; the file does not grow beyond what a few thousand requests in four minutes need.
 */
final class AgentReplayStore
{
    /** How long a nonce is kept: the window on both sides of the clock. */
    private const int KEEP = 2 * AgentSignature::WINDOW + 10;

    /** The most that is remembered; a flood of signed requests cannot make the file large (the oldest are dropped, which the time check covers). */
    private const int MAX = 20000;

    public function __construct(private readonly string $file)
    {
    }

    /**
     * Notes a nonce.
     *
     * @return bool true if it is new (the request may go on), false if it was seen already, or if the file cannot be used (then nothing is accepted:
     *              an agent that cannot tell a repeat from a new request must not answer)
     */
    public function remember(string $nonce, int $now): bool
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return false;
        }
        $handle = @fopen($this->file, 'c+');
        if ($handle === false) {
            return false;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }
            @chmod($this->file, 0600);
            $kept = [];
            $seen = false;
            while (($line = fgets($handle)) !== false) {
                $parts = explode(' ', trim($line), 2);
                if (count($parts) !== 2 || (int) $parts[0] < $now) {
                    continue;   // expired (or damaged)
                }
                if ($parts[1] === $nonce) {
                    $seen = true;
                }
                $kept[] = $parts[0] . ' ' . $parts[1];
            }
            if (!$seen) {
                $kept[] = ($now + self::KEEP) . ' ' . $nonce;
            }
            if (count($kept) > self::MAX) {
                $kept = array_slice($kept, -self::MAX);
            }
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, implode("\n", $kept) . "\n");
            fflush($handle);
            flock($handle, LOCK_UN);

            return !$seen;
        } finally {
            fclose($handle);
        }
    }
}
