<?php

declare(strict_types=1);

namespace ThreadSafe\Archive;

use RuntimeException;

/**
 * A ZIP archive cannot be written, or it cannot be trusted: damaged, hostile or not supported.
 */
final class ZipException extends RuntimeException
{
}
