<?php

declare(strict_types=1);

namespace ThreadSafe\Migration;

/**
 * How the target shop uses HTTPS.
 */
enum SslMode: string
{
    /** HTTP only. */
    case None = 'none';

    /** HTTPS for login and checkout pages (PS_SSL_ENABLED), HTTP elsewhere. */
    case Login = 'login';

    /** HTTPS everywhere (PS_SSL_ENABLED and PS_SSL_ENABLED_EVERYWHERE). */
    case All = 'all';

    public function sslEnabled(): bool
    {
        return $this !== self::None;
    }

    public function everywhere(): bool
    {
        return $this === self::All;
    }
}
