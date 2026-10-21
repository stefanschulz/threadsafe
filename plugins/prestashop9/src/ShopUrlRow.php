<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9;

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

    /** @return array<string, scalar> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'shopId' => $this->shopId, 'domain' => $this->domain, 'domainSsl' => $this->domainSsl, 'physicalUri' => $this->physicalUri, 'virtualUri' => $this->virtualUri, 'main' => $this->main, 'active' => $this->active];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self((int) $data['id'], (int) $data['shopId'], (string) $data['domain'], (string) $data['domainSsl'], (string) $data['physicalUri'], (string) $data['virtualUri'], (bool) $data['main'], (bool) $data['active']);
    }
}
