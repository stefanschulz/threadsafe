<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

/**
 * A precondition checked before anything is planned. A violated guard stops the run with exit code 2, e.g. "the
 * domain to replace does not appear in the shop configuration" or "the database server is not MariaDB".
 */
interface Guard
{
    /** Short name for diagnostics. */
    public function id(): string;

    /**
     * @return string|null null if satisfied, otherwise the reason why the run must not continue
     */
    public function check(Context $context): ?string;
}
