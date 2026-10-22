<?php

declare(strict_types=1);

namespace ThreadSafe\Profile;

use ThreadSafe\Crypto\AgeKeys;

/**
 * Whom the backups of a profile are encrypted for: the public keys (`age1...`) of the people who may open them
 * (docs/PHASE5.md 3.5). A key is checked in full, checksum included, so that a mistyped key is refused when it is entered and not
 * when the first backup is made for it.
 */
final readonly class EncryptionSettings
{
    private const array KEYS = ['recipients'];

    /**
     * @param non-empty-list<string> $recipients
     */
    private function __construct(public array $recipients)
    {
    }

    public static function isRecipient(string $text): bool
    {
        return AgeKeys::isRecipient($text);
    }

    /**
     * @throws ProfileException the data is not valid encryption settings
     */
    public static function fromArray(mixed $data): ?self
    {
        if ($data === null) {
            return null;
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ProfileException('"encryption" must be null or an object such as {"recipients": ["age1..."]}.');
        }
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new ProfileException("Unknown field \"$key\" in \"encryption\" (known: " . implode(', ', self::KEYS) . ').');
            }
        }
        $recipients = $data['recipients'] ?? [];
        if (!is_array($recipients) || !array_is_list($recipients)) {
            throw new ProfileException('"encryption.recipients" must be a list of public keys (age1...).');
        }
        $keys = [];
        foreach ($recipients as $recipient) {
            if (!is_string($recipient) || !self::isRecipient($recipient)) {
                throw new ProfileException('"encryption.recipients" holds something that is not a public key of the age format (age1 and 58 more characters).');
            }
            $keys[] = $recipient;
        }
        $keys = array_values(array_unique($keys));

        return $keys === [] ? null : new self($keys);
    }

    /**
     * @return array{recipients: non-empty-list<string>}
     */
    public function toArray(): array
    {
        return ['recipients' => $this->recipients];
    }
}
