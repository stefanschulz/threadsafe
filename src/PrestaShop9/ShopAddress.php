<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9;

use ThreadSafe\Core\Context;

/**
 * Where the shop is (to be) reachable after the migration: the main shop URL with the request's domain mapping, base
 * path and SSL mode applied. Works before the migration (rows still have the old domain) and after it (the new one).
 */
final readonly class ShopAddress
{
    /**
     * @param string $host   the domain the shop answers to (the https one if the shop uses https everywhere)
     * @param string $prefix base path without trailing slash: "" or "/shop"
     */
    private function __construct(public string $host, public string $prefix, public bool $ssl)
    {
    }

    public static function of(Context $context): self
    {
        $request = State::request($context);
        $db = State::connection($context);
        $source = State::source($context);

        $main = $source->shopUrls[0];
        foreach ($source->shopUrls as $row) {
            if ($row->main) {
                $main = $row;
                break;
            }
        }
        $domain = $request->mappingFrom($main->domain)->to ?? $main->domain;
        $domainSsl = $request->mappingFrom($main->domainSsl)->to ?? $main->domainSsl;

        $ssl = $request->ssl?->everywhere();
        if ($ssl === null) {
            $configuration = $db->table('configuration');
            $ssl = $db->scalar('SELECT value FROM ' . $db->quote($configuration) . " WHERE name = 'PS_SSL_ENABLED_EVERYWHERE' ORDER BY id_configuration LIMIT 1") === '1';
        }

        return new self($ssl ? $domainSsl : $domain, rtrim($request->physicalUri ?? $main->physicalUri, '/'), $ssl);
    }

    /** "http(s)://host" */
    public function origin(): string
    {
        return ($this->ssl ? 'https' : 'http') . '://' . $this->host;
    }
}
