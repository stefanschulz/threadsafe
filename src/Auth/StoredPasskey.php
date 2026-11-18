<?php

declare(strict_types=1);

namespace ThreadSafe\Auth;

/**
 * A passkey as it stands in `data/passkeys.json`: what the device made ({@see PasskeyCredential}) and what the person sees of it, a name and
 * two dates (UTC, ISO 8601; `lastUsed` is null until it logs in).
 */
final readonly class StoredPasskey
{
    public function __construct(
        public PasskeyCredential $credential,
        public string $name,
        public string $created,
        public ?string $lastUsed,
    ) {
    }

    /** "synced" if the device said that the key is eligible for backup (the flag BE), else "only on this device". */
    public function kind(): string
    {
        return $this->credential->backupEligible ? 'synced' : 'only on this device';
    }
}
