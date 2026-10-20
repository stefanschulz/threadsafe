<?php

declare(strict_types=1);

namespace ThreadSafe\Profile;

/**
 * The result of the connection test of a profile.
 */
final readonly class TestReport
{
    /**
     * @param list<Check> $checks
     */
    public function __construct(public string $profile, public array $checks)
    {
    }

    /** No check failed (warnings do not stop a backup). */
    public function passed(): bool
    {
        return $this->count(CheckLevel::Failure) === 0;
    }

    public function count(CheckLevel $level): int
    {
        return count(array_filter($this->checks, static fn (Check $c): bool => $c->level === $level));
    }

    /**
     * @return list<string> the messages of the checks with that level
     */
    public function messages(CheckLevel $level): array
    {
        return array_values(array_map(
            static fn (Check $c): string => $c->subject . ': ' . $c->message,
            array_filter($this->checks, static fn (Check $c): bool => $c->level === $level),
        ));
    }

    /** @return array{profile: string, passed: bool, checks: list<array{level: string, subject: string, message: string}>} */
    public function toArray(): array
    {
        return ['profile' => $this->profile, 'passed' => $this->passed(), 'checks' => array_map(static fn (Check $c): array => $c->toArray(), $this->checks)];
    }
}
