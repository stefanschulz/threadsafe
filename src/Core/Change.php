<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

/**
 * One intended modification, as shown in the dry-run report and journalled when it is applied.
 *
 * A change may stand for many similar modifications ($count), e.g. "text column ps_cms_lang.content: 10 rows",
 * so that large runs stay readable. Values that must not be shown (passwords, keys) are flagged $secret and are
 * masked by the report.
 */
final readonly class Change
{
    public function __construct(
        public ChangeKind $kind,
        public string $target,
        public string $description,
        public ?string $before = null,
        public ?string $after = null,
        public int $count = 1,
        public bool $secret = false,
    ) {
    }

    public static function create(string $target, string $description, ?string $after = null, int $count = 1, bool $secret = false): self
    {
        return new self(ChangeKind::Create, $target, $description, null, $after, $count, $secret);
    }

    public static function update(string $target, string $description, ?string $before = null, ?string $after = null, int $count = 1, bool $secret = false): self
    {
        return new self(ChangeKind::Update, $target, $description, $before, $after, $count, $secret);
    }

    public static function delete(string $target, string $description, ?string $before = null, int $count = 1, bool $secret = false): self
    {
        return new self(ChangeKind::Delete, $target, $description, $before, null, $count, $secret);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'target' => $this->target,
            'description' => $this->description,
            'before' => $this->secret && $this->before !== null ? '***' : $this->before,
            'after' => $this->secret && $this->after !== null ? '***' : $this->after,
            'count' => $this->count,
        ];
    }
}
