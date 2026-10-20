<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

/**
 * Record of everything an applied run changed, detailed enough to revert it by hand: old and new values of
 * database rows and copies of overwritten files. Phase 1 has no automatic undo; the journal makes a manual revert
 * possible.
 *
 * The journal contains old and new values, which can include passwords and keys. It must live in a protected
 * place and be readable only by its owner.
 */
interface Journal
{
    /** Marks the start of a step; the entries that follow belong to it. */
    public function step(string $stepId): void;

    /**
     * A changed database value.
     *
     * @param array<string, scalar|null> $key primary key of the row, e.g. ['id_configuration' => 4]
     */
    public function database(string $table, array $key, string $column, ?string $old, ?string $new): void;

    /**
     * A changed, created or deleted file. For an existing file the original has been copied by
     * {@see backupFile()} and the copy's name is passed as $backup.
     *
     * @param 'create'|'update'|'delete' $action
     */
    public function file(string $path, string $action, ?string $backup = null): void;

    /**
     * Copies a file that is about to be overwritten or deleted into the journal. Returns the name of the copy
     * (relative to the journal directory) or null if the file does not exist.
     */
    public function backupFile(string $path): ?string;

    /**
     * Free-form entry (a decision, a skipped item …).
     *
     * @param array<string, scalar|null> $context
     */
    public function note(string $message, array $context = []): void;

    /** Path of the journal file, null if nothing is written (dry run). */
    public function path(): ?string;
}
