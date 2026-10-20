<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

/**
 * Journal that writes nothing; used for dry runs.
 */
final class NullJournal implements Journal
{
    public function step(string $stepId): void
    {
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
    }

    public function path(): ?string
    {
        return null;
    }
}
