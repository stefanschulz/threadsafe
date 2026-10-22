<?php

declare(strict_types=1);

namespace ThreadSafe\Crypto;

/**
 * Whom a file is encrypted for: something that can wrap the 16 byte file key into a stanza of the header.
 */
interface Recipient
{
    /**
     * @param string $fileKey 16 random bytes
     */
    public function wrap(string $fileKey): Stanza;

    /** Whether the stanza must be the only one in the header (the passphrase: a file for a passphrase cannot also open with a weaker key). */
    public function mustBeAlone(): bool;
}
