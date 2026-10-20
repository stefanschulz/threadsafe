<?php

declare(strict_types=1);

namespace ThreadSafe\Profile;

/**
 * How serious the result of one check of the connection test is.
 */
enum CheckLevel: string
{
    /** In order. */
    case Ok = 'ok';

    /** A fact worth knowing; nothing to do. */
    case Info = 'info';

    /** Works, but with a limit or a risk the person should know. */
    case Warning = 'warning';

    /** A backup cannot be made like this. */
    case Failure = 'failure';
}
