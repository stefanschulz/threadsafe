<?php

declare(strict_types=1);

namespace ThreadSafe\Storage;

use ThreadSafe\Backup\BackupName;
use ThreadSafe\Backup\Sidecar;

/**
 * One backup as the storage directory shows it, for a listing.
 */
final readonly class BackupEntry
{
    /** The files are there as the sidecar file says and it records a status. */
    public const string COMPLETE = 'complete';

    /** The sidecar file says there are parts that are missing or have another size. */
    public const string INCOMPLETE = 'incomplete';

    /** Only unfinished files: a run is going or died. */
    public const string UNFINISHED = 'unfinished';

    /** Nothing usable can be read about it. */
    public const string UNKNOWN = 'unknown';

    /**
     * @param string      $condition COMPLETE, INCOMPLETE, UNFINISHED or UNKNOWN
     * @param bool        $rebuilt   the sidecar file was missing or unusable and has been made again from the last part
     * @param string|null $problem   what is wrong, for the person
     */
    public function __construct(
        public BackupName $name,
        public ?Sidecar $sidecar,
        public string $condition,
        public bool $rebuilt = false,
        public ?string $problem = null,
    ) {
    }

    /** The word for the status column: the recorded status, or what is wrong. */
    public function label(): string
    {
        return $this->condition === self::COMPLETE && $this->sidecar !== null ? $this->sidecar->status->value : $this->condition;
    }

    /** @return array<string, mixed> */
    public function toArray(string $profile): array
    {
        return [
            'profile' => $profile,
            'backup' => $this->name->base(),
            'time' => $this->name->time->format('Y-m-d\TH:i:s\Z'),
            'status' => $this->label(),
            'size' => $this->sidecar?->size(),
            'parts' => $this->sidecar === null ? null : array_map(static fn ($p): string => $p->file, $this->sidecar->parts),
            'application' => $this->sidecar === null ? null : trim($this->sidecar->manifest->application['name'] . ' ' . ($this->sidecar->manifest->application['version'] ?? '')),
            'consistency' => $this->sidecar?->manifest->consistency,
            'pinned' => $this->sidecar !== null && $this->sidecar->pinned,
            'encrypted' => $this->sidecar?->encryption !== null,
            'source' => $this->sidecar === null || $this->sidecar->source === '' ? null : $this->sidecar->source,
            'warnings' => $this->sidecar === null ? null : count($this->sidecar->manifest->warnings),
            'rebuilt' => $this->rebuilt,
            'problem' => $this->problem,
        ];
    }
}
