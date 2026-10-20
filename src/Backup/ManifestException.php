<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

use RuntimeException;

/**
 * A manifest or sidecar file is damaged or of another format.
 */
final class ManifestException extends RuntimeException
{
}
