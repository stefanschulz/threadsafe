<?php

declare(strict_types=1);

namespace ThreadSafe\Remote;

/**
 * {@see AgentTransport} over PHP streams (`allow_url_fopen`), for a machine without the curl extension. The certificate of an https host is checked
 * (the host's certificate authorities, `openssl.cafile`), a redirect is not followed, a body that goes to a sink is read in blocks of 1 MB.
 */
final class StreamTransport implements AgentTransport
{
    public function send(string $method, string $url, array $headers = [], string $body = '', ?callable $sink = null, int $timeout = 60): AgentResponse
    {
        $started = microtime(true);
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $context = stream_context_create([
            'http' => ['method' => $method, 'header' => implode("\r\n", $lines), 'content' => $body, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => $timeout],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            $last = error_get_last();

            return new AgentResponse(0, error: $last['message'] ?? 'the connection failed', seconds: microtime(true) - $started);
        }
        stream_set_timeout($stream, $timeout);
        $raw = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : [];
        $status = 0;
        $received = [];
        foreach ($raw as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m) === 1) {
                $status = (int) $m[1];
                $received = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $received[strtolower(trim($name))] = trim($value);
            }
        }
        $collected = '';
        $error = null;
        $stopped = false;
        while (!feof($stream)) {
            $chunk = fread($stream, 1048576);
            if ($chunk === false || stream_get_meta_data($stream)['timed_out']) {
                $error = 'the connection broke off or timed out';
                break;
            }
            if ($chunk === '') {
                continue;
            }
            if ($sink !== null && $status >= 200 && $status < 300) {
                if ($sink($status, $chunk) === false) {
                    $stopped = true;

                    break;
                }
            } else {
                $collected .= $chunk;
            }
        }
        fclose($stream);

        return new AgentResponse($error !== null ? 0 : $status, $received, $collected, $error, microtime(true) - $started, $stopped);
    }
}
