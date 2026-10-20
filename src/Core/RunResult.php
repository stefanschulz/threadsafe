<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

use Throwable;

/**
 * Outcome of {@see Runner::run()}: the report, the exit code and the failure (if any) that ended the run.
 */
final readonly class RunResult
{
    public function __construct(
        public Report $report,
        public ExitCode $exitCode,
        public ?Throwable $failure = null,
    ) {
    }

    public function isOk(): bool
    {
        return $this->exitCode === ExitCode::Ok;
    }
}
