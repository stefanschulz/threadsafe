<?php

declare(strict_types=1);

namespace ThreadSafe\Schedule;

use RuntimeException;

/**
 * A cron expression that cannot be used, or schedule state that cannot be read or written. The message says why.
 */
final class ScheduleException extends RuntimeException
{
}
