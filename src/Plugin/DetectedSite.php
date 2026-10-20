<?php

declare(strict_types=1);

namespace ThreadSafe\Plugin;

/**
 * What a website type found in a directory.
 */
final readonly class DetectedSite
{
    /**
     * @param string               $application name of the application, e.g. "PrestaShop"
     * @param string|null          $version     its version, null if it has none to read
     * @param array<string, mixed> $details     what the type needs later (PrestaShop: the folder of the back office)
     */
    public function __construct(
        public string $application,
        public ?string $version,
        public array $details = [],
    ) {
    }

    /** "PrestaShop 9.0.2" */
    public function describe(): string
    {
        return $this->version === null ? $this->application : $this->application . ' ' . $this->version;
    }

    /** A string detail, or null. */
    public function detail(string $key): ?string
    {
        $value = $this->details[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
