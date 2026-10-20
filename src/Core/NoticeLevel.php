<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

enum NoticeLevel: string
{
    /** Something worth knowing ("already correct", "left untouched on purpose"). */
    case Info = 'info';

    /** Something a human should look at, but the run can continue. */
    case Warning = 'warning';
}
