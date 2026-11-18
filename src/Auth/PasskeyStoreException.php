<?php

declare(strict_types=1);

namespace ThreadSafe\Auth;

/**
 * The file of the passkeys cannot be read or written, or does not hold what {@see PasskeyStore} writes. A passkey is an extra way in: a file
 * in this state takes the passkeys away for the moment and **never** the password login (docs/PHASE14.md 6, criterion 4).
 */
final class PasskeyStoreException extends PasskeyException
{
}
