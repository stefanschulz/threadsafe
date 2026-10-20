<?php

declare(strict_types=1);

namespace ThreadSafe\Replace\Exception;

use RuntimeException;

/**
 * A value looked like a structured format but is not valid in it.
 *
 * @internal
 */
final class UnparseableValue extends RuntimeException
{
}
