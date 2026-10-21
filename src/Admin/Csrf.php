<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use ThreadSafe\Web\Request;

/**
 * The two checks that keep a foreign page from making the browser of a logged-in administrator change something
 * (docs/PHASE4.md 3.2): the token that every form and every script request carries, and the origin of a POST.
 */
final class Csrf
{
    /** The header the script sends the token in. */
    public const string HEADER = 'x-csrf-token';

    /** The name of the form field. */
    public const string FIELD = 'csrf';

    /**
     * Whether the request carries the token of this session, in the form field or the header.
     */
    public static function valid(Request $request, string $expected): bool
    {
        $given = $request->post(self::FIELD) ?? $request->header(self::HEADER) ?? '';

        return $expected !== '' && hash_equals($expected, $given);
    }

    /**
     * Whether a request that changes something comes from a page of this site. The browser names the origin in `Origin` (or, for
     * older ones, `Referer`); when neither is sent the request is let through, because the session cookie (SameSite=Strict) and
     * the token still have to match.
     */
    public static function sameOrigin(Request $request): bool
    {
        $host = strtolower($request->header('host') ?? '');
        $source = $request->header('origin') ?? $request->header('referer');
        if ($source === null || $host === '') {
            return true;
        }
        if ($source === 'null') {
            return false;
        }
        $parts = parse_url($source);
        if ($parts === false || !isset($parts['host'])) {
            return false;
        }
        $given = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if ($given === $host) {
            return true;
        }

        // the Host header may omit the default port that the Origin names, or the other way round
        $strip = static fn (string $value): string => preg_replace('/:(80|443)$/', '', $value) ?? $value;

        return $strip($given) === $strip($host);
    }

    private function __construct()
    {
    }
}
