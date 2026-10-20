<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

use RuntimeException;

/**
 * The command line is wrong: unknown command or option, missing or invalid argument. Exit code 1.
 */
final class UsageError extends RuntimeException
{
}
