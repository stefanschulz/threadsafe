<?php

declare(strict_types=1);

namespace ThreadSafe\Update;

/**
 * The answer of the health check after the switch: the new version started and said so (`ok`), did not (`failed`), or could not be asked
 * (`skipped`, with the reason: no way to make a request to the own address).
 */
final readonly class HealthResult
{
    public const string OK = 'ok';

    public const string FAILED = 'failed';

    public const string SKIPPED = 'skipped';

    private function __construct(public string $status, public string $message)
    {
    }

    public static function ok(string $message = ''): self
    {
        return new self(self::OK, $message);
    }

    public static function failed(string $message): self
    {
        return new self(self::FAILED, $message);
    }

    public static function skipped(string $message): self
    {
        return new self(self::SKIPPED, $message);
    }
}
