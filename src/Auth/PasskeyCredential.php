<?php

declare(strict_types=1);

namespace ThreadSafe\Auth;

/**
 * A passkey as the server knows it after a registration: the credential id (the raw bytes), the public key, the counter of the device and
 * what the device said about itself (the flags for a synced key, the AAGUID, the transports). It holds public data only. The store
 * of work package 3 adds a name and the dates.
 */
final readonly class PasskeyCredential
{
    /**
     * @param list<string> $transports
     */
    public function __construct(
        public string $id,
        public CoseKey $key,
        public int $signCount,
        public bool $backupEligible,
        public bool $backupState,
        public string $aaguid,
        public array $transports,
    ) {
    }

    /** The same key after a login: the counter and the state of the backup are the ones the device reported. */
    public function afterLogin(int $signCount, bool $backupState): self
    {
        return new self($this->id, $this->key, $signCount, $this->backupEligible, $backupState, $this->aaguid, $this->transports);
    }
}
