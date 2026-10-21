<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use ThreadSafe\Web\Request;

/**
 * What a handler needs to know about the request it answers: the request itself, the addresses, and the session (null on the
 * pages before the login). The handler may change {@see $session}; the kernel saves it afterwards.
 */
final class Context
{
    /**
     * @param array<string, mixed>|null $session the data of the session, null if nobody is logged in
     */
    public function __construct(
        public readonly Request $request,
        public readonly Urls $urls,
        public ?array $session = null,
        public readonly ?string $sessionToken = null,
    ) {
    }

    /** The name of the logged-in user, or null. */
    public function user(): ?string
    {
        return is_string($this->session['user'] ?? null) ? $this->session['user'] : null;
    }

    /** The CSRF token of the session, or an empty string before the login. */
    public function csrf(): string
    {
        return is_string($this->session['csrf'] ?? null) ? $this->session['csrf'] : '';
    }

    /**
     * Keeps a message for the next page that is shown.
     *
     * @param string $type "ok", "warning" or "error"
     */
    public function flash(string $type, string $message): void
    {
        if ($this->session === null) {
            return;
        }
        $flashes = is_array($this->session['flash'] ?? null) ? $this->session['flash'] : [];
        $flashes[] = ['type' => $type, 'message' => $message];
        $this->session['flash'] = array_slice($flashes, -5);
    }

    /**
     * The kept messages; they are removed from the session.
     *
     * @return list<array{type: string, message: string}>
     */
    public function takeFlashes(): array
    {
        $out = [];
        foreach (is_array($this->session['flash'] ?? null) ? $this->session['flash'] : [] as $flash) {
            if (is_array($flash) && is_string($flash['type'] ?? null) && is_string($flash['message'] ?? null)) {
                $out[] = ['type' => $flash['type'], 'message' => $flash['message']];
            }
        }
        if ($this->session !== null) {
            unset($this->session['flash']);
        }

        return $out;
    }
}
