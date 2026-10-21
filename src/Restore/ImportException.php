<?php

declare(strict_types=1);

namespace ThreadSafe\Restore;

use RuntimeException;

/**
 * The database import cannot go on: a statement failed, or the dump cannot be imported the way it was asked for. The message
 * says where (file and byte offset) but never shows the values of a statement.
 */
final class ImportException extends RuntimeException
{
}
