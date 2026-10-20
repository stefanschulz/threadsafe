<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9;

/**
 * Keys of the `configuration` table that hold the shop's own domain and are therefore set explicitly by the
 * UpdateConfiguration step instead of being rewritten like ordinary content.
 */
final class ConfigurationKeys
{
    public const array DOMAIN = ['PS_SHOP_DOMAIN', 'PS_SHOP_DOMAIN_SSL'];

    public const array MEDIA_SERVER = ['PS_MEDIA_SERVER_1', 'PS_MEDIA_SERVER_2', 'PS_MEDIA_SERVER_3'];

    public const string SSL_ENABLED = 'PS_SSL_ENABLED';

    public const string SSL_EVERYWHERE = 'PS_SSL_ENABLED_EVERYWHERE';

    /**
     * All keys whose domain value UpdateConfiguration handles.
     *
     * @return list<string>
     */
    public static function domainValued(): array
    {
        return [...self::DOMAIN, ...self::MEDIA_SERVER];
    }

    private function __construct()
    {
    }
}
