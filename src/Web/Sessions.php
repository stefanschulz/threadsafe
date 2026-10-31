<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

use Closure;

/**
 * Who is logged in, kept in files of the state directory (not in PHP sessions, which a host may not have or may lose): a random
 * token in a cookie, and next to it a file with the CSRF token and what the person has chosen so far. The file name is a hash of
 * the token, so a listing of the directory gives no valid cookie. A session ends after two idle hours (or the time given), and,
 * if an absolute lifetime is given, that long after it began however busy it was.
 */
final class Sessions
{
    public const int IDLE = 7200;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param int                 $idle     seconds without a request after which a session ends
     * @param int|null            $absolute seconds after its creation at which a session ends in any case; null = no such limit
     * @param Closure(): int|null $clock    for tests: the time in seconds
     */
    public function __construct(private readonly string $directory, ?Closure $clock = null, private readonly int $idle = self::IDLE, private readonly ?int $absolute = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @return array{string, array<string, mixed>} the token for the cookie and the data of the new session
     */
    public function create(): array
    {
        $this->collectOld();
        $token = bin2hex(random_bytes(32));
        $data = ['csrf' => bin2hex(random_bytes(24)), 'created' => ($this->clock)(), 'last' => ($this->clock)()];
        $this->save($token, $data);

        return [$token, $data];
    }

    /**
     * @return array<string, mixed>|null null if there is no such session or it has expired
     */
    public function load(?string $token): ?array
    {
        if ($token === null || preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }
        $path = $this->path($token);
        $content = is_file($path) ? @file_get_contents($path) : false;
        $data = $content === false ? null : json_decode($content, true);
        if (!is_array($data) || !is_int($data['last'] ?? null) || !is_string($data['csrf'] ?? null)) {
            return null;
        }
        $now = ($this->clock)();
        if ($data['last'] < $now - $this->idle || ($this->absolute !== null && is_int($data['created'] ?? null) && $data['created'] < $now - $this->absolute)) {
            @unlink($path);

            return null;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(string $token, array $data): void
    {
        $data['last'] = ($this->clock)();
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0700, true);
        }
        $path = $this->path($token);
        $temporary = $path . '.' . bin2hex(random_bytes(3)) . '.tmp';
        if (@file_put_contents($temporary, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write the session file in "' . $this->directory . '".');
        }
        @chmod($temporary, 0600);
        @rename($temporary, $path);
    }

    public function destroy(string $token): void
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) === 1) {
            @unlink($this->path($token));
        }
    }

    /**
     * Ends every session but one (the administrator has one login, so "every other" is every session of the directory, those that wait for
     * a second factor included): used when the second factor is switched on or off, so that a session that somebody else may hold ends
     * with the change.
     *
     * @return int how many sessions ended
     */
    public function destroyExcept(string $token): int
    {
        $keep = preg_match('/^[0-9a-f]{64}$/', $token) === 1 ? $this->path($token) : null;
        $ended = 0;
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            if ($file !== $keep && @unlink($file)) {
                ++$ended;
            }
        }

        return $ended;
    }

    private function path(string $token): string
    {
        return $this->directory . '/' . hash('sha256', $token) . '.json';
    }

    private function collectOld(): void
    {
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            if ((int) @filemtime($file) < ($this->clock)() - max($this->idle, $this->absolute ?? 0)) {
                @unlink($file);
            }
        }
    }
}
