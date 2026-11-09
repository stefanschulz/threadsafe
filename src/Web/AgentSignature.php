<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

use Closure;

/**
 * The signature of a request to the agent (docs/PHASE13.md 3.1): no password and no `Authorization` header, three custom headers instead,
 *
 * ```
 * X-ThreadSafe-Time:      the time of the sender, whole seconds
 * X-ThreadSafe-Nonce:     16 to 64 characters of a-z A-Z 0-9 - _, used once
 * X-ThreadSafe-Signature: base64url (no padding) of HMAC-SHA256 over "TS1 LF METHOD LF path-and-query LF time LF nonce LF sha256(body)"
 * ```
 *
 * with a 256-bit secret that both sides got at pairing. The host takes a time within {@see WINDOW} seconds of its own, and remembers the nonces
 * of that window ({@see AgentReplayStore}), so that a request that was seen on the way cannot be sent again. The path and query are covered, so a
 * signed `?api=status` cannot be turned into a signed `?api=start`; the body is covered by its hash.
 *
 * The same class signs (the central side) and checks (the host); M0 showed that the headers arrive on the owner's host (docs/PHASE13.md section 8).
 */
final class AgentSignature
{
    /** The seconds that a request may differ from the clock of the host. */
    public const int WINDOW = 120;

    public const string HEADER_TIME = 'X-ThreadSafe-Time';

    public const string HEADER_NONCE = 'X-ThreadSafe-Nonce';

    public const string HEADER_SIGNATURE = 'X-ThreadSafe-Signature';

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param Closure(): int|null $clock for tests: the time in seconds
     */
    public function __construct(private readonly AgentReplayStore $replays, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** The text that is signed. */
    public static function canonical(string $method, string $uri, int $time, string $nonce, string $body): string
    {
        return implode("\n", ['TS1', strtoupper($method), $uri, (string) $time, $nonce, hash('sha256', $body)]);
    }

    public static function sign(string $secret, string $method, string $uri, int $time, string $nonce, string $body): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', self::canonical($method, $uri, $time, $nonce, $body), $secret, true)), '+/', '-_'), '=');
    }

    /** A new nonce. */
    public static function nonce(): string
    {
        return bin2hex(random_bytes(12));
    }

    /**
     * The headers for a request (the central side).
     *
     * @return array<string, string> header name => value
     */
    public static function headers(string $secret, string $method, string $uri, string $body = '', ?int $time = null, ?string $nonce = null): array
    {
        $time ??= time();
        $nonce ??= self::nonce();

        return [
            self::HEADER_TIME => (string) $time,
            self::HEADER_NONCE => $nonce,
            self::HEADER_SIGNATURE => self::sign($secret, $method, $uri, $time, $nonce, $body),
        ];
    }

    /**
     * Whether a request is signed with the secret, within the window, and new.
     *
     * @return string|null null if it is good, otherwise why not: "headers" (missing or not of the shape), "signature" (a stranger: nothing is said to
     *                     the sender), "time" (signed, but the clocks differ by more than the window: the sender may be told) or "replay" (signed, and seen before)
     */
    public function verify(string $secret, Request $request): ?string
    {
        $time = $request->header('x-threadsafe-time');
        $nonce = $request->header('x-threadsafe-nonce');
        $signature = $request->header('x-threadsafe-signature');
        if ($time === null || $nonce === null || $signature === null
            || preg_match('/^\d{1,12}$/D', $time) !== 1 || preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $nonce) !== 1 || preg_match('/^[A-Za-z0-9_-]{43}$/D', $signature) !== 1) {
            return 'headers';
        }
        // the signature first: only a sender who holds the secret is told that its clock is wrong (a stranger learns nothing from the window)
        $expected = self::sign($secret, $request->method, $request->uri, (int) $time, $nonce, $request->body);
        if (!hash_equals($expected, $signature)) {
            return 'signature';
        }
        $now = ($this->clock)();
        if (abs($now - (int) $time) > self::WINDOW) {
            return 'time';
        }

        // only a request that is signed is remembered: a stranger cannot fill the store with nonces
        return $this->replays->remember($nonce, $now) ? null : 'replay';
    }
}
