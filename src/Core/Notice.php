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

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return ['level' => $this->level->value, 'message' => $this->message, 'target' => $this->target];
    }
}
