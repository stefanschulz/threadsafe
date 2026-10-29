<?php

declare(strict_types=1);

namespace ThreadSafe\Replace;

use InvalidArgumentException;

/**
 * One domain to be replaced by another, e.g. "shop.example" (old) → "localhost:8190" (new).
 *
 * Domains are compared case-insensitively and stored in lower case. A port is part of the domain name
 * ("localhost:8190"). Only ASCII host names are supported (IDNs must be given in punycode).
 *
 * The new address may carry a **path** (`/sub`): a site that moves from the root of its old domain into a directory of the new one. The
 * matcher then writes that path behind the new host in every URL it replaces (`https://old.example/x` becomes `https://new.example/sub/x`). The
 * old address is taken to be at the root of its domain: a path that is to change is not something a replacement of hosts can do.
 */
final readonly class DomainMapping
{
    private const string DOMAIN_PATTERN = '/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::\d{1,5})?$/';

    public string $from;
    public string $to;

    /**
     * @param string|null $scheme   force "http" or "https" for URLs with an explicit scheme; null keeps the scheme
     * @param bool        $keepWww  true: "www.<from>" becomes "www.<to>"; false (default): the "www." prefix is dropped
     * @param string      $toPath   the path of the new address, "" (the root) or "/sub" or "/a/b", without a trailing slash
     */
    public function __construct(
        string $from,
        string $to,
        public ?string $scheme = null,
        public bool $keepWww = false,
        public string $toPath = '',
    ) {
        $this->from = self::normalise($from, 'from');
        $this->to = self::normalise($to, 'to');

        if ($this->from === $this->to) {
            throw new InvalidArgumentException("Mapping \"{$this->from}\" to itself is not a mapping.");
        }
        if ($scheme !== null && $scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException('Scheme must be "http", "https" or null.');
        }
        if ($toPath !== '' && preg_match('#^(?:/[A-Za-z0-9._~-]+)+$#', $toPath) !== 1) {
            throw new InvalidArgumentException('The path of the new address is empty or begins with a slash and has no slash at its end, e.g. "/sub".');
        }
    }

    /** The same mapping with the new address in a directory of the new domain. */
    public function withPath(string $path): self
    {
        return new self($this->from, $this->to, $this->scheme, $this->keepWww, rtrim($path, '/'));
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
