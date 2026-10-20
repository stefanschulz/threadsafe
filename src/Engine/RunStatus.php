<?php

declare(strict_types=1);

namespace ThreadSafe\Engine;

enum RunStatus: string
{
    /** Created or between ticks. Without a held lock a run in this status is interrupted (its process died). */
    case Running = 'running';

    case Finished = 'finished';

    case Failed = 'failed';
}
