<?php

declare(strict_types=1);

namespace ThreadSafe\Plugin;

use RuntimeException;

/**
 * A plugin cannot be loaded: broken manifest, missing class, unsupported plugin API.
 */
final class PluginError extends RuntimeException
{
}
