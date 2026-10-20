<?php

declare(strict_types=1);

namespace ThreadSafe\Tests\Support;

use ThreadSafe\Core\Journal;

/**
 * Journal that remembers the calls it received.
 */
final class RecordingJournal implements Journal
{
    /** @var list<string> */
    public array $steps = [];

    /** @var list<string> */
    public array $notes = [];

    public function step(string $stepId): void
    {
        $this->steps[] = $stepId;
    }

    public function database(string $table, array $key, string $column, ?string $old, ?string $new): void
    {
    }

    public function file(string $path, string $action, ?string $backup = null): void
    {
    }

    public function backupFile(string $path): ?string
    {
        return null;
    }

    public function note(string $message, array $context = []): void
    {
        $this->notes[] = $message;
    }

    public function path(): ?string
    {
        return null;
    }
}
