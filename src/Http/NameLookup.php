<?php

declare(strict_types=1);

namespace ThreadSafe\Http;

/**
 * The one reason for "no answer" that says nothing about the site: this server cannot look up the **name** of the site (the DNS record is not
 * there yet, or the server has no resolver of its own, as in a container). The self-test then has not seen the site at all, so it does not call
 * the site broken: it reports that it could not look, and says what to do (field test of a WordPress restore, 2026-11-09: the restore ended as
 * failed, with the site working, and the wizard offered no way to finish it).
 *
 * Every other reason (connection refused, timed out, a certificate PHP does not trust) stays a failure: those may be the site's own doing.
 */
final class NameLookup
{
    /** The request got no answer because the name of the site could not be resolved. */
    public static function failed(HttpResult $result): bool
    {
        if ($result->status !== 0 || $result->problem === null || !str_starts_with($result->problem, 'no answer from')) {
            return false;
        }
        $text = strtolower($result->problem);

        return str_contains($text, 'getaddrinfo') || str_contains($text, 'name resolution');
    }

    /**
     * Whether **every** request of a self-test failed on the lookup of the name. One page that answers, or one that fails otherwise, is
     * information about the site, and the self-test judges it as before.
     *
     * @param list<HttpResult> $results
     */
    public static function allFailed(array $results): bool
    {
        if ($results === []) {
            return false;
        }
        foreach ($results as $result) {
            if (!self::failed($result)) {
                return false;
            }
        }

        return true;
    }

    /** The warning that stands in the report instead of the failure. */
    public static function warning(string $host): string
    {
        return sprintf(
            'Not checked: this server cannot look up the name "%s", so the self-test could not ask the site anything. Nothing is known about the site from this check; the files and the database are restored. '
            . 'Open the site in a browser (the front page and the login of the administration). If the name does not lead to this server yet, set its DNS record; '
            . 'if only this server cannot look up its own names, that is a matter for the host (a `hosts` entry that points the name to this server).',
            $host,
        );
    }

    private function __construct()
    {
    }
}
