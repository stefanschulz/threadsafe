<?php

declare(strict_types=1);

namespace ThreadSafe\Migration;

/**
 * What the migrated site is going to be.
 */
enum Mode: string
{
    /** A real shop: keeps mail, payments and cloud links as they are. */
    case Production = 'production';

    /** A development or staging copy: outgoing mail is intercepted, live payment and cloud connections are cut. */
    case Dev = 'dev';
}
