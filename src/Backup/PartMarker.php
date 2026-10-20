<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

use JsonException;

/**
 * The small file `threadsafe-part.json` in every archive part: which backup the part belongs to and which part it is, so
 * that a part found alone can be recognised and the parts of a split backup put in order.
 */
final readonly class PartMarker
{
    /** The name of the marker inside a part. */
    public const string ENTRY = 'threadsafe-part.json';

    public function __construct(public string $backupId, public int $index, public bool $split)
    {
    }

    public function toJson(): string
    {
        return json_encode(['format' => 1, 'backupId' => $this->backupId, 'part' => $this->index, 'split' => $this->split], JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * @throws ManifestException
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ManifestException('The part marker is not valid JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($data) || ($data['format'] ?? null) !== 1 || !is_string($data['backupId'] ?? null) || !is_int($data['part'] ?? null)
            || $data['part'] < 1 || !is_bool($data['split'] ?? null)) {
            throw new ManifestException('Not a ThreadSafe part marker.');
        }

        return new self($data['backupId'], $data['part'], $data['split']);
    }
}
