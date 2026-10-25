<?php

declare(strict_types=1);

namespace ThreadSafe\Update;

use Closure;
use ThreadSafe\Core\AtomicFile;
use ThreadSafe\Http\HttpClient;

/**
 * The health check after the switch (docs/PHASE7.md 3.5): stores a token the new version alone is asked about, requests
 * `<address>/health/<token>` through the installation's own address, and wants the answer of the **new** version. The request is served
 * by a new process that reads the new pointer, so it is the new release that starts - and, at its first start, migrates the data
 * ({@see Migrator}); a release that cannot start, or cannot migrate, does not answer, and the update is taken back.
 */
final class HttpHealthCheck
{
    public const string FILE = 'health.json';

    private const int LIFETIME = 120;

    /**
     * @param string       $address    the address of the interface, e.g. `https://example.org/threadsafe` (the directory of `index.php`)
     * @param string       $hostHeader the host the address is for, with the port if it is not the default one
     * @param Closure|null $transport  for tests: as for {@see HttpClient}
     * @param Closure|null $clock      for tests: the time in seconds
     */
    public function __construct(private readonly string $address, private readonly string $hostHeader, private readonly ?Closure $transport = null, private readonly ?Closure $clock = null)
    {
    }

    /** Whether this PHP can make the request at all. */
    public static function possible(): bool
    {
        return filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
    }

    public function check(string $data, string $expectedVersion): HealthResult
    {
        if ($this->transport === null && !self::possible()) {
            return HealthResult::skipped('This PHP may not make requests to its own address (allow_url_fopen is off), so the new version could not be started to check it.');
        }
        $file = rtrim($data, '/\\') . '/' . self::FILE;
        $token = bin2hex(random_bytes(16));
        $now = $this->clock !== null ? ($this->clock)() : time();
        try {
            AtomicFile::write($file, json_encode(['token' => $token, 'expires' => $now + self::LIFETIME], JSON_THROW_ON_ERROR));
        } catch (\RuntimeException $e) {
            return HealthResult::skipped('The token for the health check cannot be stored: ' . $e->getMessage());
        }
        try {
            $client = new HttpClient(false, 30, 3, $this->transport);
            $result = $client->get(rtrim($this->address, '/') . '/health/' . $token, $this->hostHeader);
        } finally {
            @unlink($file);
        }
        if ($result->status === 0) {
            return HealthResult::failed('The new version did not answer: ' . ($result->problem ?? 'no answer') . '.');
        }
        $answer = json_decode($result->body, true);
        if ($result->status !== 200 || !is_array($answer) || ($answer['ok'] ?? null) !== true) {
            return HealthResult::failed("The new version answered with HTTP {$result->status} and not as expected; it did not start properly.");
        }
        if (($answer['version'] ?? null) !== $expectedVersion) {
            return HealthResult::failed('The address answered as version ' . (is_string($answer['version'] ?? null) ? $answer['version'] : '?') . ', not ' . $expectedVersion . ' (a cache in front of the site, or another installation under this address).');
        }

        return HealthResult::ok('The new version started and answered as version ' . $expectedVersion . '.');
    }

    /**
     * What the new version answers to its own token (called by the web interface at `/health/<token>`): the token must match the stored one
     * and be young, and is used up by the answer.
     *
     * @return array{ok: true, version: string}|null null: not the token, or none stored
     */
    public static function answer(string $data, string $candidate, string $version, ?int $now = null): ?array
    {
        $file = rtrim($data, '/\\') . '/' . self::FILE;
        $content = @file_get_contents($file);
        $stored = is_string($content) ? json_decode($content, true) : null;
        if (!is_array($stored) || !is_string($stored['token'] ?? null) || !is_int($stored['expires'] ?? null)) {
            return null;
        }
        if ($stored['expires'] < ($now ?? time()) || !hash_equals($stored['token'], $candidate)) {
            return null;
        }
        @unlink($file);

        return ['ok' => true, 'version' => $version];
    }
}
