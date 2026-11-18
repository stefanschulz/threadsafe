<?php

declare(strict_types=1);

namespace ThreadSafe\Auth;

/**
 * The relying party of WebAuthn for the web interface: it makes the options for a registration and a login, and checks what a device
 * answers (docs/PHASE14.md 3.2). It keeps no state: the challenge, the credentials and the user handle are given to it, and what it
 * learned (a new credential, the new counter) is returned. The checks follow WebAuthn level 3, sections 7.1 and 7.2, with these decisions:
 * user presence and user verification are required, the attestation is read and not checked ("none", decision 3), the algorithm must be one
 * that {@see CoseKey} can check, and a counter that does not go up refuses the login ({@see PasskeyClonedException}).
 *
 * Every refusal is a {@see PasskeyException} whose message names the check and never holds a challenge, a key or a signature. What the page
 * shows to the person is one general text; the message is for the log of the server.
 */
final class Passkeys
{
    private const array TRANSPORTS = ['usb', 'nfc', 'ble', 'hybrid', 'internal', 'smart-card'];
    private const int TIMEOUT_MS = 120000;

    /**
     * @param string $rpId   the registrable domain the passkeys are bound to, for example `admin.example.com`
     * @param string $origin the exact origin of the page: scheme, host and, unless it is the standard one, the port
     */
    public function __construct(
        private readonly string $rpId,
        private readonly string $origin,
        private readonly string $rpName = 'ThreadSafe',
    ) {
    }

    /** 32 random bytes, to be kept by the caller until the answer comes (once, for a few minutes). */
    public static function newChallenge(): string
    {
        return random_bytes(32);
    }

    /**
     * The options for `navigator.credentials.create()`, as a JSON-ready array (binary values in base64url).
     *
     * @param list<string> $existingIds the raw ids of the passkeys that exist: one device does not make two
     *
     * @return array<string, mixed>
     */
    public function registrationOptions(string $challenge, string $userHandle, string $userName, array $existingIds): array
    {
        return [
            'challenge' => Base64Url::encode($challenge),
            'rp' => ['id' => $this->rpId, 'name' => $this->rpName],
            'user' => ['id' => Base64Url::encode($userHandle), 'name' => $userName, 'displayName' => $userName],
            'pubKeyCredParams' => array_map(static fn (int $alg): array => ['type' => 'public-key', 'alg' => $alg], CoseKey::supported()),
            'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
            'attestation' => 'none',
            'timeout' => self::TIMEOUT_MS,
            'excludeCredentials' => array_map(static fn (string $id): array => ['type' => 'public-key', 'id' => Base64Url::encode($id)], $existingIds),
        ];
    }

    /**
     * The options for `navigator.credentials.get()`: no list of credentials, so that the device offers the passkeys of this site.
     *
     * @return array<string, mixed>
     */
    public function assertionOptions(string $challenge): array
    {
        return [
            'challenge' => Base64Url::encode($challenge),
            'rpId' => $this->rpId,
            'userVerification' => 'required',
            'timeout' => self::TIMEOUT_MS,
        ];
    }

    /**
     * Checks the answer of `navigator.credentials.create()` and returns the new passkey.
     *
     * @param array<string, mixed> $answer       `clientDataJSON` and `attestationObject` in base64url; `transports` as a list of text (a hint)
     * @param list<string>         $existingIds the raw ids of the passkeys that exist
     *
     * @throws PasskeyException
     */
    public function verifyRegistration(string $challenge, array $answer, array $existingIds): PasskeyCredential
    {
        $clientDataJson = Base64Url::decode(self::text($answer, 'clientDataJSON'));
        $this->checkClientData(ClientData::parse($clientDataJson), 'webauthn.create', $challenge);

        $attestation = Cbor::decode(Base64Url::decode(self::text($answer, 'attestationObject')));
        // The statement is read and not checked (attestation "none"): only the shape is asked for
        if (!is_array($attestation) || !is_string($attestation['fmt'] ?? null) || !is_array($attestation['attStmt'] ?? null) || !is_string($attestation['authData'] ?? null)) {
            throw new PasskeyException('The attestation object has not the form that WebAuthn describes.');
        }
        $data = AuthenticatorData::parse($attestation['authData']);
        $this->checkAuthenticatorData($data);
        if (!$data->attested() || $data->credentialId === null || $data->key === null || $data->aaguid === null) {
            throw new PasskeyException('The registration carries no credential.');
        }
        foreach ($existingIds as $id) {
            if (hash_equals($id, $data->credentialId)) {
                throw new PasskeyException('This passkey is registered already.');
            }
        }

        return new PasskeyCredential($data->credentialId, $data->key, $data->counter, $data->backupEligible(), $data->backupState(), $data->aaguid, self::transports($answer['transports'] ?? []));
    }

    /**
     * Checks the answer of `navigator.credentials.get()` against the passkeys that exist and returns the one that was used, with the new
     * counter. Nothing is stored here.
     *
     * @param array<string, mixed>   $answer      `id`, `clientDataJSON`, `authenticatorData`, `signature` and, if the device sends it, `userHandle` (base64url)
     * @param list<PasskeyCredential> $credentials the passkeys that exist
     * @param string                 $userHandle  the user handle that was given at the registrations
     *
     * @throws PasskeyClonedException the counter did not go up
     * @throws PasskeyException       any other check fails
     */
    public function verifyAssertion(string $challenge, array $answer, array $credentials, string $userHandle): PasskeyCredential
    {
        $id = Base64Url::decode(self::text($answer, 'id'));
        $credential = null;
        foreach ($credentials as $candidate) {
            if (hash_equals($candidate->id, $id)) {
                $credential = $candidate;
            }
        }
        if ($credential === null) {
            throw new PasskeyException('The passkey is not known.');
        }
        $handle = $answer['userHandle'] ?? null;
        if ($handle !== null && $handle !== '' && !hash_equals($userHandle, Base64Url::decode(self::textValue($handle)))) {
            throw new PasskeyException('The user handle is not ours.');
        }

        $clientDataJson = Base64Url::decode(self::text($answer, 'clientDataJSON'));
        $this->checkClientData(ClientData::parse($clientDataJson), 'webauthn.get', $challenge);

        $authenticatorData = Base64Url::decode(self::text($answer, 'authenticatorData'));
        $data = AuthenticatorData::parse($authenticatorData);
        $this->checkAuthenticatorData($data);
        if ($data->backupEligible() !== $credential->backupEligible) {
            throw new PasskeyException('The device changed its mind about being synced.');
        }

        $signature = Base64Url::decode(self::text($answer, 'signature'));
        if (!$credential->key->verify($authenticatorData . hash('sha256', $clientDataJson, true), $signature)) {
            throw new PasskeyException('The signature is not valid.');
        }

        // The signature counts first: only a genuine answer can say that the key was cloned
        if (($data->counter !== 0 || $credential->signCount !== 0) && $data->counter <= $credential->signCount) {
            throw new PasskeyClonedException('The counter of the device did not go up.');
        }

        return $credential->afterLogin($data->counter, $data->backupState());
    }

    private function checkClientData(ClientData $data, string $type, string $challenge): void
    {
        if ($data->type !== $type) {
            throw new PasskeyException('The client data is for another kind of ceremony.');
        }
        if (!hash_equals(Base64Url::encode($challenge), $data->challenge)) {
            throw new PasskeyException('The challenge is not the one that was issued.');
        }
        if (!hash_equals($this->origin, $data->origin)) {
            throw new PasskeyException('The origin is not the expected one.');
        }
        if ($data->crossOrigin) {
            throw new PasskeyException('The ceremony ran in a frame of another origin.');
        }
    }

    private function checkAuthenticatorData(AuthenticatorData $data): void
    {
        if (!hash_equals(hash('sha256', $this->rpId, true), $data->rpIdHash)) {
            throw new PasskeyException('The answer is for another relying party id.');
        }
        if (!$data->userPresent() || !$data->userVerified()) {
            throw new PasskeyException('The user was not present and verified.');
        }
        if (!$data->backupFlagsMakeSense()) {
            throw new PasskeyException('The backup flags contradict each other.');
        }
    }

    /** @param array<string, mixed> $answer */
    private static function text(array $answer, string $name): string
    {
        return self::textValue($answer[$name] ?? null);
    }

    private static function textValue(mixed $value): string
    {
        if (!is_string($value)) {
            throw new PasskeyException('The answer lacks a value or has one of the wrong kind.');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function transports(mixed $transports): array
    {
        if (!is_array($transports)) {
            return [];
        }
        $known = [];
        foreach ($transports as $transport) {
            if (is_string($transport) && in_array($transport, self::TRANSPORTS, true) && !in_array($transport, $known, true)) {
                $known[] = $transport;
            }
        }

        return $known;
    }
}
