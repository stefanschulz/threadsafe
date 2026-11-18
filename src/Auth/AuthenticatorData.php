<?php

declare(strict_types=1);

namespace ThreadSafe\Auth;

/**
 * The authenticator data of WebAuthn (level 3, section 6.1): what a device signs. 32 bytes of the hash of the relying party id, a byte of
 * flags, a counter of 4 bytes, and, if the flag says so, the data of a new credential (the AAGUID, the credential id and the public key
 * in COSE form) and the extensions. The parts are read strictly: nothing may be left over, and the key is a {@see CoseKey}.
 */
final readonly class AuthenticatorData
{
    public const int USER_PRESENT = 0x01;
    public const int USER_VERIFIED = 0x04;
    public const int BACKUP_ELIGIBLE = 0x08;
    public const int BACKUP_STATE = 0x10;
    public const int ATTESTED = 0x40;
    public const int EXTENSIONS = 0x80;

    /** The longest credential id that the specification allows. */
    public const int MAX_CREDENTIAL_ID = 1023;

    public function __construct(
        public string $rpIdHash,
        public int $flags,
        public int $counter,
        public ?string $aaguid,
        public ?string $credentialId,
        public ?CoseKey $key,
    ) {
    }

    /**
     * @throws PasskeyException
     */
    public static function parse(string $bytes): self
    {
        if (strlen($bytes) < 37) {
            throw new PasskeyException('The authenticator data is too short.');
        }
        $flags = ord($bytes[32]);
        $counter = unpack('N', substr($bytes, 33, 4));
        if ($counter === false || !is_int($counter[1])) {
            throw new PasskeyException('The authenticator data has no counter.');
        }
        $offset = 37;
        $aaguid = null;
        $credentialId = null;
        $key = null;

        if (($flags & self::ATTESTED) !== 0) {
            if (strlen($bytes) < $offset + 18) {
                throw new PasskeyException('The data of the credential is cut.');
            }
            $aaguid = substr($bytes, $offset, 16);
            $length = unpack('n', substr($bytes, $offset + 16, 2));
            if ($length === false || !is_int($length[1]) || $length[1] < 1 || $length[1] > self::MAX_CREDENTIAL_ID) {
                throw new PasskeyException('The credential id has an impossible length.');
            }
            $offset += 18;
            if (strlen($bytes) < $offset + $length[1]) {
                throw new PasskeyException('The credential id is cut.');
            }
            $credentialId = substr($bytes, $offset, $length[1]);
            $offset += $length[1];
            [, $end] = Cbor::decodePrefix($bytes, $offset);
            $key = CoseKey::fromCbor(substr($bytes, $offset, $end - $offset));
            $offset = $end;
        }
        if (($flags & self::EXTENSIONS) !== 0) {
            [$extensions, $end] = Cbor::decodePrefix($bytes, $offset);
            if (!is_array($extensions)) {
                throw new PasskeyException('The extensions of the authenticator data are not a map.');
            }
            $offset = $end;
        }
        if ($offset !== strlen($bytes)) {
            throw new PasskeyException('The authenticator data has bytes that no flag explains.');
        }

        return new self(substr($bytes, 0, 32), $flags, $counter[1], $aaguid, $credentialId, $key);
    }

    public function userPresent(): bool
    {
        return ($this->flags & self::USER_PRESENT) !== 0;
    }

    public function userVerified(): bool
    {
        return ($this->flags & self::USER_VERIFIED) !== 0;
    }

    /** The credential can be synced to other devices (a "multi-device" passkey). */
    public function backupEligible(): bool
    {
        return ($this->flags & self::BACKUP_ELIGIBLE) !== 0;
    }

    /** The credential is backed up now. It cannot be so without being eligible. */
    public function backupState(): bool
    {
        return ($this->flags & self::BACKUP_STATE) !== 0;
    }

    public function attested(): bool
    {
        return ($this->flags & self::ATTESTED) !== 0;
    }

    public function backupFlagsMakeSense(): bool
    {
        return !$this->backupState() || $this->backupEligible();
    }
}
