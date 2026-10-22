<?php

declare(strict_types=1);

namespace ThreadSafe\Crypto;

/**
 * What opens a file: a secret key or a passphrase, as something that can find the file key in the stanzas of a header.
 */
interface Identity
{
    /**
     * @param list<Stanza> $stanzas all the stanzas of the header
     *
     * @return string|null the 16 byte file key, or null if this identity does not open any of them
     *
     * @throws CryptoException the stanza is malformed, or a passphrase would cost more than this host can do
     */
    public function unwrap(array $stanzas): ?string;
}
