<?php

declare(strict_types=1);

namespace ThreadSafe\Remote;

/**
 * How a request gets to the agent and the answer back: curl, PHP streams, or (in tests) a function that calls the agent in the same process. It knows nothing of
 * signatures or of the API; it does not follow a redirect (a POST that is redirected is another request) and it checks the certificate of an https host.
 */
interface AgentTransport
{
    /**
     * The most of a body that is kept in memory (an answer that does not go to a sink): the answers of the agent are small JSON, and the page of a host that
     * answers instead is read for its first words only. A site is not trusted with the memory of this machine: a longer body is cut and the answer says
     * `oversize`.
     */
    public const int MAX_BODY = 4194304;

    /**
     * @param array<string, string>                 $headers  header name => value
     * @param (callable(int, string): (bool|null))|null $sink given each piece of the body of an answer with a status of 2xx, together with that status; the body is then not in the answer.
     *                                                        Used for a part of a backup, which is large. Returning false stops the transfer (the answer then says `stopped`)
     * @param int                                   $timeout  seconds for a request that is not streamed; for a streamed one, seconds without a single byte
     *
     * @return AgentResponse an answer of any status; with an `error` and status 0 if the host could not be reached or the connection broke off; with
     *                       `oversize` if a body that was not streamed is longer than {@see MAX_BODY}
     */
    public function send(string $method, string $url, array $headers = [], string $body = '', ?callable $sink = null, int $timeout = 60): AgentResponse;
}
