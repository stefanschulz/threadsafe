<?php

declare(strict_types=1);

namespace ThreadSafe\Retention;

use ThreadSafe\Storage\BackupEntry;

/**
 * One thing retention deletes (or would delete) and why: a backup that a rule asks to remove, or the leftovers of a run that died.
 */
final readonly class RetentionAction
{
    public const string KEEP = 'keep';

    public const string AGE = 'maxAgeDays';

    public const string SIZE = 'maxBytes';

    public const string LEFTOVER = 'leftover';

    /**
     * @param string $rule   one of the constants above
     * @param string $reason a sentence for the person
     * @param int    $bytes  what deleting it frees
     */
    public function __construct(public BackupEntry $entry, public string $rule, public string $reason, public int $bytes)
    {
    }

    public function base(): string
    {
        return $this->entry->name->base();
    }
}
