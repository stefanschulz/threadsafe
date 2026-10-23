<?php

declare(strict_types=1);

namespace ThreadSafe\Joomla\Exception;

use RuntimeException;

/**
 * The `configuration.php` of a Joomla site cannot be read safely or does not say what the type needs: it is missing, it has no class
 * `JConfig`, a setting that is needed is missing or not understood, or it names a database that ThreadSafe cannot reach (PostgreSQL, a
 * socket).
 */
final class InvalidConfigFile extends RuntimeException
{
}
