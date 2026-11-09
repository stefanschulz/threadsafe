<?php

declare(strict_types=1);

namespace ThreadSafe\Remote;

use RuntimeException;

/**
 * Why a call to the agent did not give what was asked. {@see $kind} says what to do about it: a call that failed in transient ways (the agent could not be
 * reached, a gateway said 502 to 504) is tried again after a pause; the others are told to the person.
 */
final class AgentException extends RuntimeException
{
    /** The host could not be reached, the connection broke off or timed out, or a gateway had no answer. */
    public const string UNREACHABLE = 'unreachable';

    /** A plain "Not found": the agent is off, not paired, does not allow this address, or the secret is not its own (it says nothing more to a stranger). */
    public const string REFUSED = 'refused';

    /** The clocks differ by more than the window of the signature. */
    public const string CLOCK = 'clock';

    /** The agent says it saw this request before (a repeated nonce). */
    public const string REPLAY = 'replay';

    /** The agent answered with an error of its own (`{"error": ..., "message": ...}`); the code is the HTTP status. */
    public const string API = 'api';

    /** The answer is not what an agent says (a page of the host, a redirect, something that is not JSON). */
    public const string BAD_ANSWER = 'bad-answer';

    /** A part that arrived is not the one the agent listed (its size or its SHA-256 differs). */
    public const string MISMATCH = 'mismatch';

    /**
     * @param array<string, mixed> $details what the agent added (`hostTime`, `error`)
     */
    public function __construct(public readonly string $kind, string $message, int $status = 0, public readonly array $details = [])
    {
        parent::__construct($message, $status);
    }

    /** Whether trying again after a pause may help. */
    public function isTransient(): bool
    {
        return $this->kind === self::UNREACHABLE || ($this->kind === self::API && in_array($this->getCode(), [500, 502, 503, 504], true));
    }
}
