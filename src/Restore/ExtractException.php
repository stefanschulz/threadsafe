<?php

declare(strict_types=1);

namespace ThreadSafe\Restore;

use RuntimeException;

/**
 * Extraction cannot go on: a file cannot be written, the target holds something in the way, or the archive is damaged.
 * The target is then in a partial state, which the message says.
 */
final class ExtractException extends RuntimeException
{
}
