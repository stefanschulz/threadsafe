<?php

declare(strict_types=1);

namespace ThreadSafe\Auth;

use RuntimeException;

/**
 * A passkey input is refused: a CBOR item that is not well formed, a key that is not usable, an answer of a device that fails a check
 * (docs/PHASE14.md 3.2). The message says what is wrong with the input; it never holds a key, a signature or a challenge.
 */
final class PasskeyException extends RuntimeException
{
}
