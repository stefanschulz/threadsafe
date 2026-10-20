<?php

declare(strict_types=1);

namespace ThreadSafe\Database;

use RuntimeException;

/**
 * A statement failed or the connection broke while it ran; the same exception whichever PHP interface is used.
 */
final class DatabaseError extends RuntimeException
{
}
