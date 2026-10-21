<?php

declare(strict_types=1);

namespace ThreadSafe\Restore;

/**
 * The result of one requirement of a restore: what was found and, for a problem, what the person can do about it.
 */
final readonly class Check
{
    public const string ERROR = 'error';

    public const string WARNING = 'warning';

    public const string INFO = 'info';

    /**
     * @param string $level error (the restore does not start), warning (it can start) or info
     */
    public function __construct(public string $level, public string $message, public string $hint = '')
    {
    }

    public static function error(string $message, string $hint = ''): self
    {
        return new self(self::ERROR, $message, $hint);
    }

    public static function warning(string $message, string $hint = ''): self
    {
        return new self(self::WARNING, $message, $hint);
    }

    public static function info(string $message): self
    {
        return new self(self::INFO, $message);
    }

    public function isError(): bool
    {
        return $this->level === self::ERROR;
    }

    /** @return array{level: string, message: string, hint: string} */
    public function toArray(): array
    {
        return ['level' => $this->level, 'message' => $this->message, 'hint' => $this->hint];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['level'], (string) $data['message'], (string) ($data['hint'] ?? ''));
    }
}
