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

    /**
     * @param array<string, mixed> $data as made by {@see toArray()}
     */
    public static function fromArray(array $data): self
    {
        $strings = static function (mixed $list): array {
            $out = [];
            foreach (is_array($list) ? $list : [] as $entry) {
                if (is_string($entry)) {
                    $out[] = $entry;
                }
            }

            return $out;
        };

        return new self(
            is_string($data['backup'] ?? null) ? $data['backup'] : '',
            (($data['level'] ?? 'full') === 'full'),
            is_int($data['parts'] ?? null) ? $data['parts'] : 0,
            is_int($data['bytes'] ?? null) ? $data['bytes'] : 0,
            $strings($data['problems'] ?? null),
            is_string($data['status'] ?? null) ? BackupStatus::tryFrom($data['status']) : null,
            $strings($data['notes'] ?? null),
        );
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
