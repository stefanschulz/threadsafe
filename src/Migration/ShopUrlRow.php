<?php

declare(strict_types=1);

namespace ThreadSafe\Migration;

/**
 * One row of the shop_url table.
 */
final readonly class ShopUrlRow
{
    public function __construct(
        public int $id,
        public int $shopId,
        public string $domain,
        public string $domainSsl,
        public string $physicalUri,
        public string $virtualUri,
        public bool $main,
        public bool $active,
    ) {
    }
}
