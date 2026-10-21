<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use RuntimeException;

/**
 * The configuration of the web interface cannot be read, written or changed to that value.
 */
final class ConfigException extends RuntimeException
{
}
