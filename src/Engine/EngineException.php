<?php

declare(strict_types=1);

namespace ThreadSafe\Engine;

use RuntimeException;

/**
 * The engine cannot do what was asked: a run that does not exist, is damaged, or is already running.
 */
final class EngineException extends RuntimeException
{
    /** The code of the exception for a run that another process is working on right now. */
    public const int BUSY = 1;

    public function isBusy(): bool
    {
        return $this->getCode() === self::BUSY;
    }
}
