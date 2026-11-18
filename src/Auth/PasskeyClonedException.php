<?php

declare(strict_types=1);

namespace ThreadSafe\Auth;

/**
 * The counter of a device did not go up (WebAuthn level 3, 7.2 step 21): the key may have been copied. The login is refused, and the caller
 * writes its own audit line for this case.
 */
final class PasskeyClonedException extends PasskeyException
{
}
