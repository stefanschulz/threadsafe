<?php

declare(strict_types=1);

namespace ThreadSafe\Auth;

use RuntimeException;

/**
 * The file of the second factor cannot be read or written. The login treats a file that exists and cannot be read as a reason to
 * refuse, never as "no second factor" (docs/PHASE11.md 4.1).
 */
final class TwoFactorException extends RuntimeException
{
}
