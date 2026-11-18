<?php

declare(strict_types=1);

namespace ThreadSafe\Auth;

use JsonException;

/**
 * The client data JSON that the browser writes for every ceremony (WebAuthn level 3, section 5.8.1). It is read as JSON and the members
 * that are known are checked; **it is never compared with a template as text**, because a device may add members (Windows Hello does:
 * `other_keys_can_be_added_here`, docs/PHASE14.md 8.1). The signature covers the exact bytes, so the caller keeps those.
 */
final readonly class ClientData
{
    public const int MAX_BYTES = 8192;

    public function __construct(
        public string $type,
        public string $challenge,
        public string $origin,
        public bool $crossOrigin,
    ) {
    }

    /**
     * @throws PasskeyException
     */
    public static function parse(string $json): self
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new PasskeyException('The client data is too large.');
        }
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PasskeyException('The client data is not JSON.');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new PasskeyException('The client data is not an object.');
        }
        $type = $data['type'] ?? null;
        $challenge = $data['challenge'] ?? null;
        $origin = $data['origin'] ?? null;
        $cross = $data['crossOrigin'] ?? false;
        if (!is_string($type) || !is_string($challenge) || !is_string($origin) || !is_bool($cross)) {
            throw new PasskeyException('The client data lacks a member or has one of the wrong kind.');
        }

        return new self($type, $challenge, $origin, $cross);
    }
}
