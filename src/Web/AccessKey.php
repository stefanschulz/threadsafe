<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

use Closure;

/**
 * The key that lets a person into the wizard (docs/PHASE3.md 3.2): a PHP file `restore-key.php` next to the tool that returns the
 * secret (`<?php return 'a secret you choose';`). Fetched over HTTP such a file executes and prints nothing, where a text file
 * would be served. Nothing works without it.
 *
 * Wrong keys are slowed down by a {@see Throttle}: after three failures within a quarter of an hour the next attempt has to wait
 * (5 s, doubling, up to 5 minutes) and an attempt during the wait is not even compared. Every failure is logged (time and address).
 */
final class AccessKey
{
    public const int MIN_LENGTH = 8;

    /** The secret that the instructions show as an example; it is never accepted. */
    public const string EXAMPLE = 'a secret you choose';

    private readonly Throttle $throttle;

    /**
     * @param Closure(): int|null $clock for tests: the time in seconds
     */
    public function __construct(private readonly string $keyFile, string $attemptsFile, string $logFile, ?Closure $clock = null)
    {
        $this->throttle = new Throttle($attemptsFile, $logFile, $clock);
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
        if (self::isExample($key)) {
            return 'The secret in ' . basename($this->keyFile) . ' is the example from the instructions, which everybody knows. Choose a secret of your own.';
        }

        return null;
    }

    /** Whether the secret is the example of the instructions (and of this class's description): it would let anybody in. */
    public static function isExample(string $key): bool
    {
        return strtolower(trim($key)) === self::EXAMPLE;
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
        if ($key !== null && strlen($key) >= self::MIN_LENGTH && !self::isExample($key) && hash_equals($key, $given)) {
            $this->throttle->reset();

            return null;
        }

        $this->throttle->fail($address, 'wrong key');

        return 'This is not the key.';
    }

    /** Seconds until the next attempt is accepted; 0 if one is accepted now. */
    public function waitSeconds(): int
    {
        return $this->throttle->waitSeconds();
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
}
