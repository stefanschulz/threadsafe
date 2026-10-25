<?php

declare(strict_types=1);

namespace ThreadSafe\Install;

use RuntimeException;

/**
 * The installation cannot go on; the message says why and what to do.
 */
final class InstallException extends RuntimeException
{
}
