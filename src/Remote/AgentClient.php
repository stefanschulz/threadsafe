<?php

declare(strict_types=1);

namespace ThreadSafe\Remote;

use Closure;
use ThreadSafe\Core\Version;
use ThreadSafe\Profile\RemoteSettings;
use ThreadSafe\Web\AgentSignature;

/**
 * The central side of the conversation with an agent (docs/PHASE13.md 3.1, 3.3): every call is signed ({@see AgentSignature}), the answers are read the way the
 * agent writes them ({@see \ThreadSafe\Web\Agent}), and a part of a backup is fetched with `Range`, resumed where a broken transfer stopped, and
 * checked against the SHA-256 that the agent listed before it is handed on.
 *
 * It decides nothing about what to do next: a failure is an {@see AgentException} whose kind says whether to try again ({@see AgentException::isTransient()}).
 */
final class AgentClient
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param Closure(): int|null $clock for tests: the time in seconds
     */
    public function __construct(
        private readonly RemoteSettings $remote,
        private readonly AgentTransport $transport,
        ?Closure $clock = null,
        private readonly int $timeout = 60,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @return array<string, mixed> `api`, `tool`, `php`, `limits`, `time`, `https`, `profile` (`id`, `ready`), `space`, `keepOnHost`, `backups`, `run`
     *
     * @throws AgentException
     */
    public function status(): array
    {
        return $this->json('GET', ['api' => 'status']);
    }

    /**
     * Starts a backup on the site; a run that is going on there is taken up.
     *
     * @return array{run: string, resumed: bool}
     *
     * @throws AgentException
     */
    public function start(): array
    {
        $answer = $this->json('POST', ['api' => 'start']);

        return ['run' => (string) ($answer['run'] ?? ''), 'resumed' => ($answer['resumed'] ?? false) === true];
    }

    /**
     * One step of the run on the site.
     *
     * @return array{status: string, progress: string, percent: int, backup: string, error: string} `running`, `busy`, `failed` or `finished`
     *
     * @throws AgentException
     */
    public function tick(string $run): array
    {
        $answer = $this->json('POST', ['api' => 'tick', 'run' => $run]);

        return [
            'status' => (string) ($answer['status'] ?? 'running'),
            'progress' => (string) ($answer['progress'] ?? ''),
            'percent' => (int) ($answer['percent'] ?? 0),
            'backup' => (string) ($answer['backup'] ?? ''),
            'error' => (string) ($answer['error'] ?? ''),
        ];
    }

    /**
     * The backups on the site.
     *
     * @return list<array{backup: string, time: string, status: string, complete: bool, encrypted: bool, size: int|null, parts: list<array{file: string, size: int, sha256: string}>, sidecar: array{file: string, size: int, sha256: string}|null}>
     *
     * @throws AgentException
     */
    public function backups(): array
    {
        $answer = $this->json('GET', ['api' => 'list']);
        $list = $answer['backups'] ?? null;
        if (!is_array($list)) {
            throw new AgentException(AgentException::BAD_ANSWER, 'The list of backups is missing from the answer of the agent.');
        }

        $backups = [];
        foreach ($list as $entry) {
            if (!is_array($entry) || !is_string($entry['backup'] ?? null)) {
                continue;
            }
            $parts = [];
            foreach (is_array($entry['parts'] ?? null) ? $entry['parts'] : [] as $part) {
                if (is_array($part) && is_string($part['file'] ?? null) && is_string($part['sha256'] ?? null)) {
                    $parts[] = ['file' => $part['file'], 'size' => (int) ($part['size'] ?? 0), 'sha256' => $part['sha256']];
                }
            }
            $sidecar = $entry['sidecar'] ?? null;
            $backups[] = [
                'backup' => $entry['backup'],
                'time' => (string) ($entry['time'] ?? ''),
                'status' => (string) ($entry['status'] ?? ''),
                'complete' => ($entry['complete'] ?? false) === true,
                'encrypted' => ($entry['encrypted'] ?? false) === true,
                'size' => isset($entry['size']) ? (int) $entry['size'] : null,
                'parts' => $parts,
                'sidecar' => is_array($sidecar) && is_string($sidecar['file'] ?? null) && is_string($sidecar['sha256'] ?? null)
                    ? ['file' => $sidecar['file'], 'size' => (int) ($sidecar['size'] ?? 0), 'sha256' => $sidecar['sha256']]
                    : null,
            ];
        }

        return $backups;
    }

    /**
     * Tells the site that this machine holds the backup whole (the SHA-256 of every part); the site then keeps the newest ones by its rule.
     *
     * @param array<string, string> $parts file => SHA-256
     *
     * @return array{kept: list<string>, deleted: list<string>}
     *
     * @throws AgentException
     */
    public function done(string $backup, array $parts): array
    {
        $answer = $this->json('POST', ['api' => 'done'], (string) json_encode(['backup' => $backup, 'parts' => $parts], JSON_THROW_ON_ERROR));

        return ['kept' => array_values(array_filter((array) ($answer['kept'] ?? []), 'is_string')), 'deleted' => array_values(array_filter((array) ($answer['deleted'] ?? []), 'is_string'))];
    }

    /**
     * Gives a run on the site up.
     *
     * @throws AgentException
     */
    public function abort(string $run): void
    {
        $this->json('POST', ['api' => 'abort', 'run' => $run]);
    }

    /**
     * Fetches a part (or the sidecar file) of a backup into `$destination`: from the beginning, or from the byte that the file has already (a
     * transfer that broke off goes on), and checks it. Nothing is returned for a part that is whole and right; a part that is not is deleted.
     *
     * @param int $size   the size the agent listed
     * @param string $sha256 the SHA-256 the agent listed
     *
     * @throws AgentException the transfer broke off (the file stays, the next call resumes), or the part is not the one that was listed (the file is deleted)
     */
    public function fetch(string $backup, string $file, string $destination, int $size, string $sha256): void
    {
        $have = is_file($destination) ? (int) filesize($destination) : 0;
        if ($have > $size) {
            $this->discard($destination);
            $have = 0;
        }
        if ($have < $size) {
            $handle = null;
            $sink = static function (int $status, string $chunk) use (&$handle, $destination): void {
                if ($handle === null) {
                    // 206: the rest of the file, appended. 200: the whole file again (the agent did not honour the Range, or the file changed): from the start
                    $handle = fopen($destination, $status === 206 ? 'ab' : 'wb');
                }
                if (is_resource($handle)) {
                    fwrite($handle, $chunk);
                }
            };
            $headers = $have > 0 ? ['Range' => 'bytes=' . $have . '-', 'If-Range' => '"' . $sha256 . '"'] : [];
            try {
                $response = $this->call('GET', ['api' => 'part', 'backup' => $backup, 'file' => $file], '', $headers, $sink);
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
            if ($response->status === 416) {
                // the file on the site is shorter than what is here: it is not the one that was started
                $this->discard($destination);

                throw new AgentException(AgentException::MISMATCH, "The part \"$file\" on the site is shorter than what was fetched (it was changed): it is fetched again.", 416);
            }
            $this->check($response, partial: true);
        }
        clearstatcache(true, $destination);
        $got = is_file($destination) ? (int) filesize($destination) : 0;
        if ($got !== $size || hash_file('sha256', $destination) !== $sha256) {
            $this->discard($destination);

            throw new AgentException(AgentException::MISMATCH, "The part \"$file\" that arrived is not the one the agent listed (" . ($got !== $size ? "$got bytes instead of $size" : 'another SHA-256') . '); it is fetched again.');
        }
    }

    // ------------------------------------------------------------------------------------------------- the call

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     *
     * @throws AgentException
     */
    private function json(string $method, array $query, string $body = ''): array
    {
        $response = $this->call($method, $query, $body);
        $this->check($response, partial: false);
        $data = json_decode($response->body, true);
        if (!is_array($data)) {
            throw new AgentException(AgentException::BAD_ANSWER, 'The agent answered something that is not JSON (' . $this->words($response->body) . ').', $response->status);
        }

        return $data;
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $extra  more headers (`Range`)
     * @param (callable(int, string): void)|null $sink
     */
    private function call(string $method, array $query, string $body = '', array $extra = [], ?callable $sink = null): AgentResponse
    {
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $path = parse_url($this->remote->url, PHP_URL_PATH);
        $uri = (is_string($path) && $path !== '' ? $path : '/') . '?' . $queryString;   // as the agent sees it in REQUEST_URI: what the signature covers
        $headers = [
            ...AgentSignature::headers($this->remote->secret, $method, $uri, $body, ($this->clock)()),
            'User-Agent' => 'ThreadSafe/' . Version::CURRENT,
            'Accept' => 'application/json',
            ...($body !== '' ? ['Content-Type' => 'application/json'] : []),
            ...$extra,
        ];

        return $this->transport->send($method, $this->remote->url . '?' . $queryString, $headers, $body, $sink, $this->timeout);
    }

    /**
     * Turns what is not a good answer into an exception that says what to do.
     *
     * @param bool $partial a part may be answered with 206
     *
     * @throws AgentException
     */
    private function check(AgentResponse $response, bool $partial): void
    {
        if ($response->status === 0) {
            throw new AgentException(AgentException::UNREACHABLE, 'The agent could not be reached: ' . ($response->error ?? 'no answer') . '.');
        }
        if ($response->status >= 200 && $response->status < 300 && ($response->status !== 206 || $partial)) {
            return;
        }
        if ($response->status >= 300 && $response->status < 400) {
            throw new AgentException(AgentException::BAD_ANSWER, 'The address of the agent is redirected (HTTP ' . $response->status . ' to ' . ($this->words($response->header('location') ?? '?')) . '): use the address it is redirected to, so that a POST stays a POST.', $response->status);
        }
        $data = json_decode($response->body, true);
        if (is_array($data) && is_string($data['error'] ?? null)) {
            $message = is_string($data['message'] ?? null) ? $data['message'] : $data['error'];
            $kind = match ($data['error']) {
                'clock' => AgentException::CLOCK,
                'replay' => AgentException::REPLAY,
                default => AgentException::API,
            };

            throw new AgentException($kind, $message, $response->status, $data);
        }
        if ($response->status === 404 && trim($response->body) === 'Not found.') {
            throw new AgentException(AgentException::REFUSED, 'The agent does not answer (HTTP 404 "Not found."): it is switched off or not paired, it does not allow this machine\'s address, the secret is not its own, or the address is not the one of the tool.', 404);
        }
        if (in_array($response->status, [502, 503, 504], true)) {
            throw new AgentException(AgentException::API, 'The web server of the site has no answer (HTTP ' . $response->status . ').', $response->status);
        }

        throw new AgentException(AgentException::BAD_ANSWER, 'The agent answered HTTP ' . $response->status . ' (' . $this->words($response->body) . ').', $response->status);
    }

    private function discard(string $file): void
    {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /** The first words of a page, for a message: no markup, one line, not long, and without the secret or the signature of this request. */
    private function words(string $text): string
    {
        $text = preg_replace('~<(style|script)\b.*?</\1>~is', ' ', $text) ?? $text;
        $text = substr(trim((string) preg_replace('~\s+~', ' ', strip_tags($text))), 0, 120);

        return str_replace($this->remote->secret, '***', $text);
    }
}
