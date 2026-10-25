<?php

declare(strict_types=1);

namespace ThreadSafe\Update;

use RuntimeException;

/**
 * The update cannot go on, or must be taken back; the message says why and what to do.
 */
final class UpdateException extends RuntimeException
{
}
