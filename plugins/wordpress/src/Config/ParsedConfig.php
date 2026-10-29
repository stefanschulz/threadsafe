<?php

declare(strict_types=1);

namespace ThreadSafe\WordPress\Config;

/**
 * What {@see WordPressConfigParser} found: the settings of the file in the order they appear.
 *
 * @internal
 */
final readonly class ParsedConfig
{
    /**
     * @param list<ConfigEntry> $entries
     */
    public function __construct(public array $entries)
    {
    }
}
