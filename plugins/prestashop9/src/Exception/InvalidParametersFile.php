<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9\Exception;

use RuntimeException;

/**
 * The parameters file cannot be read safely: it is missing, or it contains anything other than a plain
 * `return array(...)` of literals.
 */
final class InvalidParametersFile extends RuntimeException
{
}
