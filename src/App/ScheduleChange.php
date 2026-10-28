<?php

declare(strict_types=1);

namespace ThreadSafe\App;

use ThreadSafe\Profile\ScheduleSettings;

/**
 * What came of changing the schedule of one profile: it was written (`CHANGED`), it was already so (`UNCHANGED`), there is no such profile
 * (`MISSING`), there was nothing to change (`NOTHING`: pausing a profile that has no schedule), or the file could not be written (`FAILED`).
 */
final readonly class ScheduleChange
{
    public const string CHANGED = 'changed';

    public const string UNCHANGED = 'unchanged';

    public const string MISSING = 'missing';

    public const string NOTHING = 'nothing';

    public const string FAILED = 'failed';

    public function __construct(
        public string $id,
        public string $name,
        public string $status,
        public ?ScheduleSettings $before = null,
        public ?ScheduleSettings $after = null,
        public string $message = '',
    ) {
    }
}
