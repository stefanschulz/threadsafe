<?php

declare(strict_types=1);

namespace ThreadSafe\Update;

/**
 * What a person can do about an update that was taken back: the finding of the health check ({@see HttpHealthCheck}) in a sentence of its own, and the
 * steps that follow from it. The check reports what PHP or the web server said; this turns it into a cause ("this server cannot look up its own name")
 * and into a way on, so that the page does not leave the person with a line of a network error. Steps may carry `code` in backticks.
 */
final class Diagnosis
{
    private const string SKIP = 'Start the update again and tick **Do not start the new version to check it**. The update is then switched without the check.';

    private const string BACK = 'After an update without the check, if this page does not open, go back with `php bin/threadsafe update --rollback`, or write the previous release into `current.php`.';

    /**
     * @param string $message the message of the health check that failed
     *
     * @return array{cause: string, steps: list<string>}
     */
    public static function explain(string $message): array
    {
        $text = strtolower($message);
        if (str_contains($text, 'name resolution') || str_contains($text, 'getaddrinfo')) {
            return [
                'cause' => 'This server cannot look up its own name in the DNS, so it could not ask the new version whether it started. The new version may be perfectly fine.',
                'steps' => [
                    self::SKIP,
                    'Or ask your host to make the name resolvable from the server itself: a `hosts` entry that points the name of this site to this server, or a working DNS resolver for PHP (in a container or a sandbox the file `/etc/resolv.conf` is often missing).',
                    self::BACK,
                ],
            ];
        }
        if (str_contains($text, 'certificate') || str_contains($text, 'ssl')) {
            return [
                'cause' => 'PHP does not trust the certificate of this site, so it could not ask the new version whether it started.',
                'steps' => [
                    self::SKIP,
                    'Or ask your host to give PHP the certificate authorities it needs (the setting `openssl.cafile` or `curl.cainfo` in `php.ini`), or to renew the certificate of this site if it has expired.',
                    self::BACK,
                ],
            ];
        }
        if (preg_match('/http (4\d\d)/', $text) === 1) {
            return [
                'cause' => 'The address answered, but not as ThreadSafe does. The page the check asked for is not served by this installation.',
                'steps' => [
                    'Look at the address: it must lead to this installation (not another site, a login page of the host, or a cache in front of it).',
                    'If every page of the interface says "not found" on this host, the rewrite rule is missing: set `web.prettyUrls` to `false` in `data/config.json` (see `docs/WEB.md`).',
                    self::SKIP,
                    self::BACK,
                ],
            ];
        }
        if (preg_match('/http (5\d\d)/', $text) === 1) {
            return [
                'cause' => 'The new version was started and failed. This is a real problem of the new version on this host, and the check did its job.',
                'steps' => [
                    'Look in the error log of PHP for this site (the host\'s control panel often has it): it names the file and the line.',
                    'Check that the PHP version and the extensions of the site meet the requirements of the new version (the page of the update refuses a package that does not meet them, but a host can differ between the web and the command line).',
                    'The previous version is active and the data is as it was, so nothing is lost. Do not skip the check to get past this: the new version would be live and broken.',
                ],
            ];
        }
        if (str_contains($text, 'answered as version')) {
            return [
                'cause' => 'Something answered in the name of this site, but as another version: a cache in front of the site, or another installation under the same address.',
                'steps' => [
                    'Clear the cache of the site (a caching plugin, a CDN, or the cache of the web server) and try again.',
                    'Check that the address of this page leads to the installation you are updating.',
                    self::SKIP,
                    self::BACK,
                ],
            ];
        }
        if (str_contains($text, 'did not answer') || str_contains($text, 'no answer')) {
            return [
                'cause' => 'This server could not reach its own address, so it could not ask the new version whether it started. The new version may be perfectly fine.',
                'steps' => [
                    self::SKIP,
                    'Or ask your host why the server cannot connect to its own public address (a firewall, a network address translation without loop-back, or a timeout), and to allow it.',
                    self::BACK,
                ],
            ];
        }

        return [
            'cause' => 'The new version did not answer the check as expected.',
            'steps' => [
                'Look in the error log of PHP for this site.',
                self::SKIP,
                self::BACK,
            ],
        ];
    }

    private function __construct()
    {
    }
}
