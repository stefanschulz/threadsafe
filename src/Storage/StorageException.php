<?php

declare(strict_types=1);

namespace ThreadSafe\Storage;

use RuntimeException;

/**
 * The storage directory or a file in it cannot be used.
 */
final class StorageException extends RuntimeException
{
}
