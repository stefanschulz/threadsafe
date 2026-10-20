<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

use Closure;

/**
 * Journal that is created only when the first entry is written. A run that applies nothing leaves no journal file
 * (and no journal directory) behind.
 */
final class LazyJournal implements Journal
{
    private ?Journal $journal = null;

    /**
     * @param Closure(): Journal $create called once, on first use
     */
    public function __construct(private readonly Closure $create)
    {
    }

    public function step(string $stepId): void
    {
        $this->journal()->step($stepId);
    }

    public function database(string $table, array $key, string $column, ?string $old, ?string $new): void
    {
        $this->journal()->database($table, $key, $column, $old, $new);
    }

    public function file(string $path, string $action, ?string $backup = null): void
    {
        $this->journal()->file($path, $action, $backup);
    }

    public function backupFile(string $path): ?string
    {
        return $this->journal()->backupFile($path);
    }

    public function note(string $message, array $context = []): void
    {
        $this->journal()->note($message, $context);
    }

    public function path(): ?string
    {
        return $this->journal?->path();
    }

    private function journal(): Journal
    {
        return $this->journal ??= ($this->create)();
    }
}
