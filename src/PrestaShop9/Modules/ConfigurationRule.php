<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9\Modules;

/**
 * What a module rule says about one `configuration` key.
 */
final readonly class ConfigurationRule
{
    public const array MIGRATE = ['replace', 'replace-bare', 'keep'];

    public const array DEV = ['keep', 'clear', 'remove'];

    /**
     * @param string $migrate replace (default: URL forms), replace-bare (the value is a bare domain) or keep (never touched)
     * @param string $dev     keep (default), clear (value becomes empty) or remove (row is deleted) in dev mode
     */
    public function __construct(
        public string $key,
        public string $migrate = 'replace',
        public string $dev = 'keep',
    ) {
    }
}
