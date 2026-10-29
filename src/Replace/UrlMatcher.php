<?php

declare(strict_types=1);

namespace ThreadSafe\Replace;

use InvalidArgumentException;

/**
 * Replaces domains in plain text, following the matching rules of docs/PHASE1.md (3.4):
 *
 *  - URL forms are replaced: "https://host", "http://www.host", protocol-relative "//host", the JSON-escaped
 *    form "http:\/\/host" (any number of backslashes) and the URL-encoded form "http%3A%2F%2Fhost".
 *  - A bare domain (no URL form) is only counted, unless replacing bare names is requested.
 *  - E-mail addresses ("name@host") are never touched.
 *  - Sub-domains ("shop.host") and longer names ("host.tld", "host-x") are different hosts and never match.
 *  - All mappings are applied in one pass, so mappings cannot chain (a ↔ b swaps work).
 *  - A domain without port does not match when a port follows ("host:8080"): that is another origin.
 *  - A mapping with a path (`DomainMapping::$toPath`) writes it behind the new host of every URL, in the notation of the slashes of the
 *    text it found ("/sub", "\/sub", "%2Fsub").
 */
final class UrlMatcher
{
    /** Two slashes in any notation: "//", "\/\/", "\\/\\/" or "%2F%2F". */
    private const string SLASHES = '(?:\\\\*/|%2F){2}';

    /** What must not follow a host name: more name characters, or a dot followed by a name character. */
    private const string TAIL = '(?![A-Za-z0-9_-])(?!\\.[A-Za-z0-9])';

    /** @var array<string, DomainMapping> keyed by lower-case "from" */
    private array $byFrom = [];

    /** @var list<string> host names without port, lower case, for the cheap pre-check */
    private array $hosts = [];

    private string $regex;

    private string $emailRegex;

    /**
     * @param list<DomainMapping> $mappings
     */
    public function __construct(array $mappings)
    {
        if ($mappings === []) {
            throw new InvalidArgumentException('At least one domain mapping is required.');
        }

        foreach ($mappings as $mapping) {
            if (isset($this->byFrom[$mapping->from])) {
                throw new InvalidArgumentException("Domain \"{$mapping->from}\" is mapped twice.");
            }
            $this->byFrom[$mapping->from] = $mapping;
            $this->hosts[] = $mapping->fromHost();
        }
        $this->hosts = array_values(array_unique($this->hosts));

        // Longest name first so that "shop.example:8080" wins over "shop.example".
        $froms = array_keys($this->byFrom);
        usort($froms, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $alternatives = [];
        foreach ($froms as $from) {
            // A port's colon may be percent-encoded in URL-encoded text ("host%3A8190").
            $quoted = str_replace(preg_quote(':', '~'), '(?::|%3A)', preg_quote($from, '~'));
            $alternatives[] = $quoted . ($this->byFrom[$from]->fromHasPort() ? '' : '(?!:\\d)');
        }
        $host = '(?:' . implode('|', $alternatives) . ')';
        $slashes = self::SLASHES;
        $tail = self::TAIL;

        $this->regex = '~'
            // URL with scheme
            . '(?<![A-Za-z0-9])(?<scheme>https?(?::|%3A))(?<sl>' . $slashes . ')(?<www>www\\.)?(?<h1>' . $host . ')' . $tail
            // protocol-relative URL
            . '|(?<![A-Za-z0-9/\\\\:%])(?<sl2>' . $slashes . ')(?<www2>www\\.)?(?<h2>' . $host . ')' . $tail
            // bare domain, not part of a longer name and not an e-mail domain
            . '|(?<![A-Za-z0-9@._%\\-])(?<www3>www\\.)?(?<h3>' . $host . ')' . $tail
            . '~i';

        $this->emailRegex = '~@' . $host . $tail . '~i';
    }

    /**
     * Host names (without port, lower case) of all old domains; useful for pre-filtering rows in SQL.
     *
     * @return list<string>
     */
    public function hosts(): array
    {
        return $this->hosts;
    }

    /** Cheap check whether the text can contain any of the domains at all. */
    public function mayContain(string $text): bool
    {
        foreach ($this->hosts as $host) {
            if (stripos($text, $host) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param bool $replaceBare replace bare domain names too (default: only count them)
     */
    public function rewrite(string $text, bool $replaceBare, Stats $stats): string
    {
        if (!$this->mayContain($text)) {
            return $text;
        }

        $emails = preg_match_all($this->emailRegex, $text);
        if (is_int($emails)) {
            $stats->emailsSkipped += $emails;
        }

        $result = preg_replace_callback(
            $this->regex,
            function (array $m) use ($replaceBare, $stats): string {
                $host = (string) ($m['h1'] ?? $m['h2'] ?? $m['h3']);
                $hostEncoded = stripos($host, '%3A') !== false;
                $mapping = $this->byFrom[strtolower(str_ireplace('%3A', ':', $host))];

                if (($m['h3'] ?? null) !== null) {
                    if (!$replaceBare) {
                        ++$stats->bareFound;

                        return (string) $m[0];
                    }
                    ++$stats->bareReplaced;

                    return (($m['www3'] ?? null) !== null && $mapping->keepWww ? 'www.' : '') . ($hostEncoded ? rawurlencode($mapping->to) : $mapping->to);
                }

                ++$stats->urlsReplaced;
                $slashes = (string) ($m['sl'] ?? $m['sl2']);
                $encoded = $hostEncoded || stripos($slashes, '%2F') !== false;
                $www = ($m['www'] ?? $m['www2'] ?? null) !== null && $mapping->keepWww ? 'www.' : '';
                $scheme = (string) ($m['scheme'] ?? '');
                if ($scheme !== '' && $mapping->scheme !== null) {
                    // keep the notation of the colon ("https:" or "https%3A")
                    $scheme = $mapping->scheme . substr($scheme, strlen($scheme) - (str_ends_with(strtolower($scheme), '%3a') ? 3 : 1));
                }

                // a path that the new address carries, in the notation of the slashes that were found: one "/" is the first half of "//"
                $path = '';
                if ($mapping->toPath !== '') {
                    $unit = substr($slashes, 0, intdiv(strlen($slashes), 2));
                    $path = $unit . str_replace('/', $unit, substr($mapping->toPath, 1));
                }

                return $scheme . $slashes . $www . ($encoded ? rawurlencode($mapping->to) : $mapping->to) . $path;
            },
            $text,
            -1,
            $count,
            PREG_UNMATCHED_AS_NULL,
        );

        if ($result === null) {
            // PCRE error (e.g. backtrack or JIT stack limit): never guess, leave the value alone.
            ++$stats->unparseable;

            return $text;
        }

        return $result;
    }
}
