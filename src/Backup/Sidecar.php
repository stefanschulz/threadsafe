<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

use JsonException;

/**
 * The small file next to the archive (`<base>.json`): the manifest, plus size and SHA-256 of every part, the status and
 * the list of transfers to other places. Listing backups reads only these files, never the archives. It is a convenience:
 * the archives are the truth, and a missing sidecar can be rebuilt from the last part.
 *
 * ```json
 * {"format": 1, "status": "ok", "size": 123, "parts": [{"file", "size", "sha256", "entries"}], "transfers": [], "pinned": true, "source": "schedule", "encryption": {...}, "manifest": {...}}
 * (`pinned`, `source` and `encryption` are optional: a file from before phase 5 has none of them; for an encrypted backup the manifest is the
 * minimal one, {@see Manifest::minimal()})
 * ```
 */
final readonly class Sidecar
{
    public const int FORMAT = 1;

    /**
     * @param list<PartRecord>                                                          $parts
     * @param list<array{target: string, time: string, success: bool, message: string}> $transfers
     * @param bool                                                                      $pinned    retention must not delete it (docs/PHASE5.md 3.4); lost when the sidecar file is made again from the archive
     * @param string                                                                    $source    `manual` or `schedule`; empty for a backup made before phase 5
     * @param array{format: string, recipients: list<string>, passphrase: bool}|null    $encryption null for a backup that is not encrypted; otherwise the format, the short forms of the public keys it was made
     *                                                                                  for (to find the right secret key), and whether it is for a passphrase
     */
    public function __construct(
        public Manifest $manifest,
        public array $parts,
        public BackupStatus $status,
        public array $transfers = [],
        public bool $pinned = false,
        public string $source = '',
        public ?array $encryption = null,
    ) {
    }

    /** Total size of all parts in bytes. */
    public function size(): int
    {
        return array_sum(array_map(static fn (PartRecord $p): int => $p->size, $this->parts));
    }

    public function withStatus(BackupStatus $status): self
    {
        return new self($this->manifest, $this->parts, $status, $this->transfers, $this->pinned, $this->source, $this->encryption);
    }

    /**
     * @param array{target: string, time: string, success: bool, message: string} $transfer
     */
    public function withTransfer(array $transfer): self
    {
        return new self($this->manifest, $this->parts, $this->status, [...$this->transfers, $transfer], $this->pinned, $this->source, $this->encryption);
    }

    public function withPinned(bool $pinned): self
    {
        return new self($this->manifest, $this->parts, $this->status, $this->transfers, $pinned, $this->source, $this->encryption);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'status' => $this->status->value,
            'size' => $this->size(),
            'parts' => array_map(static fn (PartRecord $p): array => $p->toArray(), $this->parts),
            'transfers' => $this->transfers,
            ...($this->pinned ? ['pinned' => true] : []),
            ...($this->source !== '' ? ['source' => $this->source] : []),
            ...($this->encryption !== null ? ['encryption' => $this->encryption] : []),
            'manifest' => $this->manifest->toArray(),
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * @throws ManifestException the text is not a sidecar file of this format
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ManifestException('The sidecar file is not valid JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT || !is_array($data['manifest'] ?? null)) {
            throw new ManifestException('Not a ThreadSafe sidecar file of format ' . self::FORMAT . '.');
        }
        $status = is_string($data['status'] ?? null) ? BackupStatus::tryFrom($data['status']) : null;
        if ($status === null || !is_array($data['parts'] ?? null) || !is_array($data['transfers'] ?? null)) {
            throw new ManifestException('The sidecar file is damaged.');
        }

        $parts = [];
        foreach ($data['parts'] as $part) {
            if (!is_array($part) || !is_string($part['file'] ?? null) || !is_int($part['size'] ?? null) || !is_string($part['sha256'] ?? null)
                || preg_match('/^[0-9a-f]{64}$/', $part['sha256']) !== 1 || !is_int($part['entries'] ?? null)) {
                throw new ManifestException('The sidecar file is damaged (parts).');
            }
            $parts[] = new PartRecord($part['file'], $part['size'], $part['sha256'], $part['entries']);
        }
        $transfers = [];
        foreach ($data['transfers'] as $transfer) {
            if (!is_array($transfer) || !is_string($transfer['target'] ?? null) || !is_string($transfer['time'] ?? null)
                || !is_bool($transfer['success'] ?? null) || !is_string($transfer['message'] ?? null)) {
                throw new ManifestException('The sidecar file is damaged (transfers).');
            }
            $transfers[] = ['target' => $transfer['target'], 'time' => $transfer['time'], 'success' => $transfer['success'], 'message' => $transfer['message']];
        }

        $source = is_string($data['source'] ?? null) && in_array($data['source'], ['manual', 'schedule'], true) ? $data['source'] : '';

        $encryption = null;
        if (isset($data['encryption'])) {
            $e = $data['encryption'];
            if (!is_array($e) || !is_string($e['format'] ?? null) || !is_array($e['recipients'] ?? null) || !is_bool($e['passphrase'] ?? null)) {
                throw new ManifestException('The sidecar file is damaged (encryption).');
            }
            $encryption = ['format' => $e['format'], 'recipients' => array_values(array_filter($e['recipients'], 'is_string')), 'passphrase' => $e['passphrase']];
        }

        return new self(Manifest::fromArray($data['manifest']), $parts, $status, $transfers, ($data['pinned'] ?? false) === true, $source, $encryption);
    }
}
