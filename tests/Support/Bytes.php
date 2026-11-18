<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

/** A byte string for the CBOR writer of {@see SoftwareAuthenticator} (a plain PHP string is written as text). */
final readonly class Bytes
{
    public function __construct(public string $bytes)
    {
    }
}
