<?php

declare(strict_types=1);

namespace ThreadSafe\Engine;

use RuntimeException;

/**
 * The engine cannot do what was asked: a run that does not exist, is damaged, or is already running.
 */
final class EngineException extends RuntimeException
{
}
