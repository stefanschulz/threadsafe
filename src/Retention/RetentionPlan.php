<?php

declare(strict_types=1);

namespace ThreadSafe\Retention;

/**
 * What retention would do to the storage of one profile right now, in the order it would do it (oldest first), and what it leaves
 * alone and why. A plan changes nothing; {@see Retention::apply()} carries it out.
 */
final readonly class RetentionPlan
{
    /**
     * @param list<RetentionAction> $delete    backups to delete, the oldest first; the numbers behind each reason are those after the deletions before it
     * @param list<RetentionAction> $leftovers unfinished files of runs that died, older than a week and claimed by no run
     * @param list<string>          $notes     what the person should know: what is protected, a limit that cannot be met, why nothing is done
     * @param int                   $usable    how many backups count (complete and usable)
     * @param int                   $bytes     their size together
     * @param bool                  $blocked   the storage cannot be read, so nothing may be deleted
     */
    public function __construct(
        public array $delete = [],
        public array $leftovers = [],
        public array $notes = [],
        public int $usable = 0,
        public int $bytes = 0,
        public bool $blocked = false,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->delete === [] && $this->leftovers === [];
    }

    /** What the deletions would free. */
    public function freed(): int
    {
        return array_sum(array_map(static fn (RetentionAction $a): int => $a->bytes, [...$this->delete, ...$this->leftovers]));
    }
}
