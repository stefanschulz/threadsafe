<?php

declare(strict_types=1);

namespace ThreadSafe\Migration;

use InvalidArgumentException;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Replace\DomainMapping;
use ThreadSafe\Replace\UrlMatcher;

/**
 * Everything the user asks for in one migration run.
 *
 * Without domain mappings the run is a restore in place (same domain, see docs/CONCEPT.md 4.6): only the steps that
 * are needed because the *environment* changed (database credentials, caches, directories) do anything.
 */
final readonly class MigrationRequest
{
    /** @var list<DomainMapping> */
    public array $domains;

    /**
     * @param string                $root         root directory of the PrestaShop installation
     * @param list<DomainMapping>   $domains      old → new domains; empty = restore in place
     * @param DatabaseSettings|null $database     connection to use; null = the values in parameters.php
     * @param string|null           $physicalUri  new base path such as "/" or "/shop/"; null = unchanged
     * @param SslMode|null          $ssl          null = unchanged
     * @param list<string>          $skipTables   additional tables (without prefix) the content replacement must skip
     * @param bool                  $replaceBare  also replace bare domain names (host name without URL form) in content
     * @param int                   $chunkSize    rows per query and transaction in the content replacement
     * @param string|null           $serverPath   where the web server sees the shop, if that differs from $root (e.g. ThreadSafe
     *                                            runs in another container); used for absolute paths written into files
     * @param MailCatcher|null      $mailCatcher  dev mode: the SMTP server that receives all mail; null = the shop sends no mail
     * @param list<string>          $devSkip      dev mode: ids of the rules that must not be applied
     * @param string|null           $devRulesFile dev mode: JSON file with more rules (same format as plugins/prestashop9/resources/dev-mode.json)
     * @param string|null           $rulesDir     directory with the user's module rules; null = data/rules next to the program, if present
     * @param bool                  $selfTest     check the migrated shop over HTTP at the end of a run
     * @param string|null           $selfTestUrl  address the web server answers on, if that differs from the shop's domain (e.g. http://web-container); the shop's path is added
     * @param bool                  $selfTestInsecure do not verify TLS certificates in the self-test
     * @param bool                  $finalize     let PrestaShop itself clear its caches (and regenerate .htaccess and robots.txt after a path change)
     */
    public function __construct(
        public string $root,
        array $domains = [],
        public ?DatabaseSettings $database = null,
        public ?string $physicalUri = null,
        public ?SslMode $ssl = null,
        public Mode $mode = Mode::Production,
        public array $skipTables = [],
        public bool $replaceBare = false,
        public int $chunkSize = 2000,
        public ?string $serverPath = null,
        public ?MailCatcher $mailCatcher = null,
        public array $devSkip = [],
        public ?string $devRulesFile = null,
        public ?string $rulesDir = null,
        public bool $selfTest = true,
        public ?string $selfTestUrl = null,
        public bool $selfTestInsecure = false,
        public bool $finalize = true,
    ) {
        if ($root === '') {
            throw new InvalidArgumentException('The shop root directory must not be empty.');
        }
        if ($physicalUri !== null && preg_match('#^/(?:[A-Za-z0-9._~-]+/)*$#', $physicalUri) !== 1) {
            throw new InvalidArgumentException('The base path must start and end with "/", e.g. "/" or "/shop/".');
        }
        if ($chunkSize < 1 || $chunkSize > 100000) {
            throw new InvalidArgumentException('The chunk size must be between 1 and 100000.');
        }

        $seen = [];
        foreach ($domains as $mapping) {
            if (isset($seen[$mapping->from])) {
                throw new InvalidArgumentException("Domain \"{$mapping->from}\" is mapped twice.");
            }
            $seen[$mapping->from] = true;
        }
        $this->domains = $domains;
    }

    /** The shop's directory as the web server sees it, without a trailing slash. */
    public function serverPath(): string
    {
        return rtrim($this->serverPath ?? $this->root, '/\\');
    }

    public function isDevMode(): bool
    {
        return $this->mode === Mode::Dev;
    }

    /** True if no domain is to be replaced (restore on the same domain). */
    public function isRestoreInPlace(): bool
    {
        return $this->domains === [];
    }

    public function urlMatcher(): ?UrlMatcher
    {
        return $this->domains === [] ? null : new UrlMatcher($this->domains);
    }

    /** The mapping whose old domain is $domain (compared case-insensitively), or null. */
    public function mappingFrom(string $domain): ?DomainMapping
    {
        $domain = strtolower($domain);
        foreach ($this->domains as $mapping) {
            if ($mapping->from === $domain) {
                return $mapping;
            }
        }

        return null;
    }

    /** True if $domain is the new domain of one of the mappings. */
    public function isTargetDomain(string $domain): bool
    {
        $domain = strtolower($domain);
        foreach ($this->domains as $mapping) {
            if ($mapping->to === $domain) {
                return true;
            }
        }

        return false;
    }
}
