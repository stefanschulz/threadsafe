<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

/**
 * What a step actually did.
 */
final readonly class StepResult
{
    /**
     * @param int          $applied number of individual modifications performed
     * @param list<Notice> $notices things that came up while applying
     */
    public function __construct(
        public int $applied,
        public array $notices = [],
    ) {
    }

    /**
     * The inverse of {@see toArray()}.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self((int) $data['applied'], array_map(static fn (mixed $n): Notice => Notice::fromArray((array) $n), array_values((array) ($data['notices'] ?? []))));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'applied' => $this->applied,
            'notices' => array_map(static fn (Notice $n): array => $n->toArray(), $this->notices),
        ];
    }
}
