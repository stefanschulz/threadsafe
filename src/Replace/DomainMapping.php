<?php

declare(strict_types=1);

namespace ThreadSafe\Replace;

use InvalidArgumentException;

/**
 * One domain to be replaced by another, e.g. "shop.example" (old) → "localhost:8190" (new).
 *
 * Domains are compared case-insensitively and stored in lower case. A port is part of the domain name
 * ("localhost:8190"). Only ASCII host names are supported (IDNs must be given in punycode).
 */
final readonly class DomainMapping
{
    private const string DOMAIN_PATTERN = '/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::\d{1,5})?$/';

    public string $from;
    public string $to;

    /**
     * @param string|null $scheme   force "http" or "https" for URLs with an explicit scheme; null keeps the scheme
     * @param bool        $keepWww  true: "www.<from>" becomes "www.<to>"; false (default): the "www." prefix is dropped
     */
    public function __construct(
        string $from,
        string $to,
        public ?string $scheme = null,
        public bool $keepWww = false,
    ) {
        $this->from = self::normalise($from, 'from');
        $this->to = self::normalise($to, 'to');

        if ($this->from === $this->to) {
            throw new InvalidArgumentException("Mapping \"{$this->from}\" to itself is not a mapping.");
        }
        if ($scheme !== null && $scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException('Scheme must be "http", "https" or null.');
        }
    }

    /** The host name without port. */
    public function fromHost(): string
    {
        return explode(':', $this->from, 2)[0];
    }

    public function fromHasPort(): bool
    {
        return str_contains($this->from, ':');
    }

    private static function normalise(string $domain, string $what): string
    {
        $domain = strtolower(trim($domain));
        if (preg_match(self::DOMAIN_PATTERN, $domain) !== 1) {
            throw new InvalidArgumentException("Invalid \"{$what}\" domain \"{$domain}\" (ASCII host name with optional port expected).");
        }

        return $domain;
    }
}
