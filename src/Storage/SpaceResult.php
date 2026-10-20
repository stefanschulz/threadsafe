<?php

declare(strict_types=1);

namespace ThreadSafe\Storage;

/**
 * The outcome of the free-space check before a backup.
 */
final readonly class SpaceResult
{
    /**
     * @param int|null    $free    bytes free, null if the system could not say
     * @param string|null $warning set when the backup may go on although something is doubtful (forced, or space unknown)
     */
    public function __construct(public bool $allowed, public ?int $free, public int $required, public ?string $warning = null)
    {
    }

    /** What to tell a person if the backup is not allowed. */
    public function refusal(): string
    {
        return sprintf(
            'Not enough free space in the storage directory: %s free, about %s needed. Free some space, choose another storage directory, or run with --force to try anyway.',
            $this->free === null ? 'unknown' : SpaceCheck::format($this->free),
            SpaceCheck::format($this->required),
        );
    }
}
