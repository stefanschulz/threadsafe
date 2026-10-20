<?php

declare(strict_types=1);

namespace ThreadSafe\Core\Exception;

use RuntimeException;

/**
 * The run must not start (or continue) because a precondition is not met. Exit code 2.
 */
class GuardFailed extends RuntimeException
{
}
