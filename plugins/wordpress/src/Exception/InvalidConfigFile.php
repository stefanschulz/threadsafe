<?php

declare(strict_types=1);

namespace ThreadSafe\WordPress\Exception;

use RuntimeException;

/**
 * The `wp-config.php` of a WordPress site cannot be read safely, or does not say what the type needs: it is missing, a setting that is needed
 * is missing, computed or defined more than once, or it names a database that ThreadSafe cannot reach (a socket). The message names the
 * setting and never its value: the file holds the password of the database and the salts.
 */
final class InvalidConfigFile extends RuntimeException
{
}
