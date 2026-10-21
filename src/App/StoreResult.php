<?php

declare(strict_types=1);

namespace ThreadSafe\App;

use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Profile\TestReport;

/**
 * What came of an attempt to store a profile: the profile, the connection test that was run first (null if it was skipped),
 * whether the profile was stored, and the reason if the file could not be written.
 */
final readonly class StoreResult
{
    public function __construct(public SiteProfile $profile, public ?TestReport $report, public bool $stored, public ?string $error = null)
    {
    }

    /** The profile did not pass its test and was therefore left alone. */
    public function rejectedByTest(): bool
    {
        return !$this->stored && $this->error === null;
    }
}
