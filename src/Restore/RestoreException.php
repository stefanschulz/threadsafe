<?php

declare(strict_types=1);

namespace ThreadSafe\Restore;

use RuntimeException;
use ThreadSafe\Core\ExitCode;

/**
 * A restore cannot start or cannot go on. The message says what and where; the exit code says which kind of failure it is
 * (2: refused before anything was written, 3: a phase failed, 4: the restored site does not pass its self-test).
 */
final class RestoreException extends RuntimeException
{
    public function __construct(string $message, public readonly ExitCode $exitCode = ExitCode::StepFailed, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
