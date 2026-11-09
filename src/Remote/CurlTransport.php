<?php

declare(strict_types=1);

namespace ThreadSafe\Remote;

/**
 * {@see AgentTransport} over the curl extension: the certificate of an https host is checked, a redirect is not followed, a body that goes to a sink is
 * not collected in memory, and a transfer that stalls (no byte for `$timeout` seconds) is cut, but one that is only large is not.
 */
final class CurlTransport implements AgentTransport
{
    public function send(string $method, string $url, array $headers = [], string $body = '', ?callable $sink = null, int $timeout = 60): AgentResponse
    {
        $started = microtime(true);
        $handle = curl_init($url);
        if ($handle === false) {
            return new AgentResponse(0, error: 'curl could not be started');
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $received = [];
        $status = 0;
        $collected = '';
        $stopped = false;
        /** @var array<int, mixed> $options */
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_ENCODING => 'identity',   // a Range counts the bytes of the file, not of a compressed copy
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$received, &$status): int {
                if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m) === 1) {
                    $status = (int) $m[1];
                    $received = [];   // a new answer (after a "100 Continue", say)
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $received[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$status, &$collected, &$stopped, $sink): int {
                if ($sink !== null && $status >= 200 && $status < 300) {
                    if ($sink($status, $chunk) === false) {
                        $stopped = true;

                        return 0;   // curl ends the transfer
                    }
                } else {
                    $collected .= $chunk;
                }

                return strlen($chunk);
            },
        ];
        if ($sink === null) {
            $options[CURLOPT_TIMEOUT] = $timeout;
        } else {
            $options[CURLOPT_LOW_SPEED_LIMIT] = 1;
            $options[CURLOPT_LOW_SPEED_TIME] = $timeout;
        }
        if ($body !== '') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($handle, $options);
        $ok = curl_exec($handle);
        $error = $ok === false && !$stopped ? curl_error($handle) : null;
        $status = $status !== 0 ? $status : (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return new AgentResponse($error !== null ? 0 : $status, $received, $collected, $error !== null ? ($error !== '' ? $error : 'the connection failed') : null, microtime(true) - $started, $stopped);
    }
}
