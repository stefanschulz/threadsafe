<?php

declare(strict_types=1);

namespace ThreadSafe\Profile;

/**
 * One line of the connection test.
 */
final readonly class Check
{
    public function __construct(public CheckLevel $level, public string $subject, public string $message)
    {
    }

    public static function ok(string $subject, string $message): self
    {
        return new self(CheckLevel::Ok, $subject, $message);
    }

    public static function info(string $subject, string $message): self
    {
        return new self(CheckLevel::Info, $subject, $message);
    }

    public static function warning(string $subject, string $message): self
    {
        return new self(CheckLevel::Warning, $subject, $message);
    }

    public static function failure(string $subject, string $message): self
    {
        return new self(CheckLevel::Failure, $subject, $message);
    }

    /** @return array{level: string, subject: string, message: string} */
    public function toArray(): array
    {
        return ['level' => $this->level->value, 'subject' => $this->subject, 'message' => $this->message];
    }
}
