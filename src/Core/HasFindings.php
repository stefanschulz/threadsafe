<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

/**
 * A step that looks at the installation before anything is changed and stores what it found in the {@see Context} for the steps
 * after it ({@see Step::plan()} of the first step usually does). A run in ticks cannot look again in the next process - the
 * installation has changed by then - so it keeps the findings in its state and puts them back into the context.
 */
interface HasFindings extends Step
{
    /**
     * What plan() stored in the context, as plain data (JSON).
     *
     * @return array<string, mixed>
     */
    public function exportFindings(Context $context): array;

    /**
     * Puts findings from {@see exportFindings()} back into the context. May open connections again.
     *
     * @param array<string, mixed> $findings
     *
     * @throws \Throwable the findings cannot be used (e.g. the database is not reachable any more)
     */
    public function importFindings(Context $context, array $findings): void;
}
