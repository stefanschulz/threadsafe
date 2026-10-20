<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

enum StepStatus: string
{
    /** Dry run: the plan has changes that would be applied. */
    case Planned = 'planned';

    /** The plan had no changes: already correct, nothing to do. */
    case Unchanged = 'unchanged';

    /** The changes were applied. */
    case Applied = 'applied';

    /** The step threw an exception. */
    case Failed = 'failed';

    /** An earlier step failed, so this one did not run. */
    case Skipped = 'skipped';
}
