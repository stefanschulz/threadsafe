<?php

declare(strict_types=1);

namespace ThreadSafe\Profile;

use RuntimeException;

/**
 * A site profile is invalid, missing or cannot be stored.
 */
final class ProfileException extends RuntimeException
{
}
