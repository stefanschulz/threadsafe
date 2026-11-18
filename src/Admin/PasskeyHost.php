<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use ThreadSafe\Auth\PasskeyException;
use ThreadSafe\Auth\Passkeys;
use ThreadSafe\Web\Request;

/**
 * The relying party id and the origin that passkeys are bound to, taken from the request (docs/PHASE14.md 3.1): the host name that the
 * browser asked for, and the scheme and port that it used. WebAuthn works only on a secure page (https, or `localhost`) and only with a host
 * *name*: a passkey cannot be bound to an IP address. The headers of a proxy (`X-Forwarded-*`) are never read.
 */
final readonly class PasskeyHost
{
    private function __construct(public string $rpId, public string $origin)
    {
    }

    /**
     * @throws PasskeyException the page cannot be used for passkeys; the message says why, in words for the person
     */
    public static function fromRequest(Request $request): self
    {
        $host = strtolower(trim((string) $request->header('host')));
        $pattern = '/\A((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*)(?::([0-9]{1,5}))?\z/';
        if (preg_match($pattern, $host, $m) !== 1 || strlen($m[1]) > 253) {
            throw new PasskeyException('Passkeys need this page to be opened by a host name, and the name that came with the request is not one.');
        }
        $name = $m[1];
        if (filter_var($name, FILTER_VALIDATE_IP) !== false || preg_match('/\A[0-9.]+\z/', $name) === 1) {
            throw new PasskeyException('Passkeys cannot be bound to an IP address. Open this page by its host name.');
        }
        $local = $name === 'localhost' || str_ends_with($name, '.localhost');
        if (!$request->https && !$local) {
            throw new PasskeyException('Passkeys need a secure page: open this one over https (browsers allow them on plain http only for localhost).');
        }
        $scheme = $request->https ? 'https' : 'http';
        $port = $m[2] ?? '';
        if ($port !== '' && (($scheme === 'https' && $port === '443') || ($scheme === 'http' && $port === '80'))) {
            $port = '';
        }

        return new self($name, $scheme . '://' . $name . ($port !== '' ? ':' . $port : ''));
    }

    public function relyingParty(): Passkeys
    {
        return new Passkeys($this->rpId, $this->origin);
    }
}
