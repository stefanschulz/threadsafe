<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

/**
 * A remark of a step that is not a change: a finding, a warning, a hint.
 */
final readonly class Notice
{
    public function __construct(
        public NoticeLevel $level,
        public string $message,
        public ?string $target = null,
    ) {
    }

    public static function info(string $message, ?string $target = null): self
    {
        return new self(NoticeLevel::Info, $message, $target);
    }

    public static function warning(string $message, ?string $target = null): self
    {
        return new self(NoticeLevel::Warning, $message, $target);
    }

    /**
     * The inverse of {@see toArray()}.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(NoticeLevel::from((string) $data['level']), (string) $data['message'], isset($data['target']) ? (string) $data['target'] : null);
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return ['level' => $this->level->value, 'message' => $this->message, 'target' => $this->target];
    }
}
