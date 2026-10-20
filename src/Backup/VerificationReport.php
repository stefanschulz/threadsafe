<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

/**
 * The outcome of verifying one backup.
 */
final readonly class VerificationReport
{
    /**
     * @param list<string> $problems
     * @param list<string> $notes    things worth saying that are not problems (the sidecar was rebuilt ...)
     */
    public function __construct(
        public string $backup,
        public bool $full,
        public int $parts,
        public int $bytes,
        public array $problems,
        public ?BackupStatus $status,
        public array $notes = [],
    ) {
    }

    public function ok(): bool
    {
        return $this->problems === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'backup' => $this->backup,
            'level' => $this->full ? 'full' : 'quick',
            'ok' => $this->ok(),
            'parts' => $this->parts,
            'bytes' => $this->bytes,
            'status' => $this->status?->value,
            'problems' => $this->problems,
            'notes' => $this->notes,
        ];
    }
}
