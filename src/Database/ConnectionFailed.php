<?php

declare(strict_types=1);

namespace ThreadSafe\Database;

use RuntimeException;

/**
 * The database cannot be reached or is not a supported server.
 */
final class ConnectionFailed extends RuntimeException
{
}
