<?php
/**
 * SPIKE (phase 13, milestone M0) - does the host let a signed, repeated, long-running, resumable conversation through?
 *
 * The plan of the connector (docs/PHASE13.md) lets a central ThreadSafe talk to an agent on a WordPress site over HTTPS: signed requests in
 * `X-ThreadSafe-*` headers, a POST with a body, steps of up to 20-30 s, and parts of a backup fetched with `Range`. A host (its web server,
 * FastCGI, a firewall plugin such as NinjaFirewall) may strip a header, refuse an unusual request, cut a long one or ignore `Range`. This file
 * finds out BEFORE anything is built, on the real host. It is read-only: it writes nothing on the host, stores nothing and prints no secret.
 *
 * One file, two roles:
 *
 *   on the machine of the central ThreadSafe (command line):
 *     php agent-probe.php make                          writes agent-probe-upload.php (this file with a fresh token in it) and
 *                                                       agent-probe.token (the token, mode 0600)
 *     php agent-probe.php check URL [options]           asks the uploaded copy at URL the questions below and prints the answers
 *         --token-file=FILE   the token (default: agent-probe.token); never a command line value
 *         --size=MB           size of the test file for the transfer (default 20)
 *         --long=SECONDS      the longest request to try (default 40; 0 = skip, the test lasts about the sum of 10, 20, 30 ... seconds)
 *         --cainfo=FILE       the bundle of certificate authorities, if this PHP has none (the answer "unable to get local issuer certificate");
 *                             without it the probe looks for one in the usual places, among them the one that Git for Windows brings
 *         --insecure          do not check the certificate of the host - for this probe only; ThreadSafe itself will always check it
 *         --remove            let the copy on the host delete itself at the end
 *         (a redirect is reported, never followed: a POST that is redirected is a different request)
 *
 *   on the host (web): upload agent-probe-upload.php where the agent will live and where the archives will lie - run the check once for
 *   each place:
 *     A  wp-content/plugins/threadsafe-probe/agent-probe-upload.php     (the agent is a PHP file; the plugin directory is made for that)
 *     B  wp-content/uploads/threadsafe-probe/agent-probe-upload.php     (many hosts and security plugins refuse PHP here: good to know)
 *   and DELETE THE FILES afterwards (the check can do it: --remove). Without the token the file answers 404 and says nothing.
 *
 * What it answers (the numbers are those of docs/PHASE13.md section 9.3, M0):
 *   1. do `X-ThreadSafe-Time`, `-Nonce` and `-Signature` arrive in PHP, and does an `Authorization` header (so the REST API of WordPress could
 *      not be used if it does not)?  Is the fallback of decision 5 (the same values in the query string) needed?
 *   2. does a POST with a body of 200 KB arrive whole, and a path with a long query (a signed request has one)?
 *   3. how long may one request run: 10, 20, 30, 40 ... seconds?  What does a cut look like (status, text)?
 *   4. does `Range` work (206, Content-Range), also through `If-Range`, and is the answer compressed behind our back?  How fast is the transfer?
 *   5. is PHP run in this directory at all, and what are the limits (max_execution_time, memory, post size, free space)?
 *   6. the difference between the clock of the host and this one (the signature has a window of 120 s).
 *   7. does a plain redirect (http to https, with or without www) sit in front of the URL?
 *   8. does a client that calls itself `ThreadSafe-Agent` get what the default client gets (some firewalls refuse unknown user agents)?
 *
 * The file is written for PHP 7.4 and later; the host of the plan runs 8.4.
 */

const AGENT_PROBE_TOKEN = '';   // `make` writes the copy that has one; web mode refuses to run while this is empty

if (PHP_SAPI !== 'cli') {
    agent_probe_web();
    exit;
}
exit(agent_probe_cli($argv));

// ------------------------------------------------------------------------------------------------------------------------------ the host

function agent_probe_web(): void
{
    $token = AGENT_PROBE_TOKEN;
    $given = isset($_GET['t']) && is_string($_GET['t']) ? $_GET['t'] : '';
    if ($token === '' || $given === '' || !hash_equals($token, $given)) {
        // like a stranger: nothing is said
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Not found\n";
        return;
    }
    @ini_set('display_errors', '0');
    $action = isset($_GET['a']) && is_string($_GET['a']) ? $_GET['a'] : 'echo';

    switch ($action) {
        case 'echo':
            agent_probe_echo();
            break;
        case 'sleep':
            $seconds = max(0, min(120, (int) ($_GET['s'] ?? 0)));
            @set_time_limit($seconds + 30);   // the PHP limit is not the question; the web server's is
            $started = microtime(true);
            sleep($seconds);
            agent_probe_json(array('slept' => round(microtime(true) - $started, 1), 'asked' => $seconds));
            break;
        case 'file':
            agent_probe_file(max(1, min(2000, (int) ($_GET['mb'] ?? 20))) * 1048576);
            break;
        case 'remove':
            agent_probe_json(array('removed' => @unlink(__FILE__)));
            break;
        default:
            http_response_code(404);
            echo "Not found\n";
    }
}

/** @param array<string, mixed> $data */
function agent_probe_json(array $data): void
{
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data), "\n";
}

function agent_probe_echo(): void
{
    $names = array();
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $names[] = substr($key, 5);   // names only: a cookie or an authorization is never shown
        }
    }
    sort($names);
    $body = file_get_contents('php://input');
    $dir = dirname(__FILE__);
    $free = @disk_free_space($dir);
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    agent_probe_json(array(
        'php' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'software' => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '',
        'directory' => $dir,
        'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '',
        'https' => $https,
        'uriLength' => isset($_SERVER['REQUEST_URI']) ? strlen($_SERVER['REQUEST_URI']) : 0,
        'headerNames' => $names,
        'ts' => array(
            'time' => isset($_SERVER['HTTP_X_THREADSAFE_TIME']) ? $_SERVER['HTTP_X_THREADSAFE_TIME'] : null,
            'nonce' => isset($_SERVER['HTTP_X_THREADSAFE_NONCE']) ? $_SERVER['HTTP_X_THREADSAFE_NONCE'] : null,
            'signature' => isset($_SERVER['HTTP_X_THREADSAFE_SIGNATURE']) ? $_SERVER['HTTP_X_THREADSAFE_SIGNATURE'] : null,
        ),
        'query' => array(
            'time' => isset($_GET['ts_time']) ? $_GET['ts_time'] : null,
            'nonce' => isset($_GET['ts_nonce']) ? $_GET['ts_nonce'] : null,
            'signature' => isset($_GET['ts_signature']) ? $_GET['ts_signature'] : null,
        ),
        'authorization' => isset($_SERVER['HTTP_AUTHORIZATION']) || isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']),
        'userAgent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 80) : '',
        'bodyBytes' => strlen($body),
        'bodySha256' => hash('sha256', $body),
        'hostTime' => microtime(true),
        'limits' => array(
            'max_execution_time' => ini_get('max_execution_time'),
            'memory_limit' => ini_get('memory_limit'),
            'post_max_size' => ini_get('post_max_size'),
            'open_basedir' => ini_get('open_basedir'),
            'disable_functions' => ini_get('disable_functions'),
        ),
        'freeBytes' => $free === false ? null : (float) $free,
        'extensions' => array('curl' => extension_loaded('curl'), 'zlib' => extension_loaded('zlib'), 'sodium' => extension_loaded('sodium'), 'openssl' => extension_loaded('openssl')),
    ));
}

/** A file of `$size` bytes that the client can make again (the chain of SHA-256 blocks of "probe-<n>"), with Range, If-Range and an ETag. */
function agent_probe_content(int $offset, int $length): string
{
    $out = '';
    $block = intdiv($offset, 32);
    $skip = $offset % 32;
    while (strlen($out) < $length + $skip) {
        $out .= hash('sha256', 'probe-' . $block, true);
        ++$block;
    }

    return substr($out, $skip, $length);
}

function agent_probe_file(int $size): void
{
    $etag = '"' . substr(hash('sha256', 'probe-file-' . $size), 0, 32) . '"';
    $start = 0;
    $end = $size - 1;
    $status = 200;
    $range = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : '';
    $ifRange = isset($_SERVER['HTTP_IF_RANGE']) ? $_SERVER['HTTP_IF_RANGE'] : '';
    if ($range !== '' && ($ifRange === '' || $ifRange === $etag) && preg_match('/^bytes=(\d+)-(\d*)$/', $range, $m) === 1) {
        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : min($size - 1, (int) $m[2]);
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            return;
        }
        $status = 206;
    }
    http_response_code($status);
    header('Content-Type: application/octet-stream');
    header('Accept-Ranges: bytes');
    header('ETag: ' . $etag);
    header('Cache-Control: no-store');
    if ($status === 206) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    header('Content-Length: ' . ($end - $start + 1));
    @set_time_limit(0);
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    $at = $start;
    while ($at <= $end) {
        $len = min(65536, $end - $at + 1);
        echo agent_probe_content($at, $len);
        $at += $len;
        flush();
    }
}

// ------------------------------------------------------------------------------------------------------------------------------ the central side

/**
 * @param list<string> $argv
 */
function agent_probe_cli(array $argv): int
{
    $command = isset($argv[1]) ? $argv[1] : '';
    $options = array();
    $positional = array();
    foreach (array_slice($argv, 2) as $argument) {
        if (strpos($argument, '--') === 0) {
            $parts = explode('=', substr($argument, 2), 2);
            $options[$parts[0]] = isset($parts[1]) ? $parts[1] : '1';
        } else {
            $positional[] = $argument;
        }
    }

    if ($command === 'make') {
        return agent_probe_make(isset($options['out']) ? $options['out'] : 'agent-probe-upload.php');
    }
    if ($command === 'check' && isset($positional[0])) {
        return agent_probe_check($positional[0], $options);
    }
    fwrite(STDERR, "Usage:\n  php agent-probe.php make [--out=FILE]\n  php agent-probe.php check URL [--token-file=FILE] [--size=MB] [--long=SECONDS] [--cainfo=FILE] [--insecure] [--remove]\n(see the top of this file)\n");

    return 2;
}

function agent_probe_make(string $out): int
{
    $source = file_get_contents(__FILE__);
    $token = bin2hex(random_bytes(16));
    $needle = 'const AGENT_PROBE_' . "TOKEN = '';";   // written in two pieces: this line must not be found itself
    $copy = str_replace($needle, 'const AGENT_PROBE_' . "TOKEN = '" . $token . "';", $source, $count);
    if ($count !== 1) {
        fwrite(STDERR, "The token line was not found in this file.\n");
        return 1;
    }
    file_put_contents($out, $copy);
    $tokenFile = 'agent-probe.token';
    file_put_contents($tokenFile, $token . "\n");
    @chmod($tokenFile, 0600);
    echo "Written: $out (upload it, once to each place named at the top of this file) and $tokenFile (stays here).\n";
    echo "Then:    php agent-probe.php check https://YOUR-SITE/wp-content/plugins/threadsafe-probe/" . basename($out) . "\n";

    return 0;
}

/**
 * How the probe treats the certificate of the host: a bundle file (null: the PHP default), or no check at all.
 *
 * @param array{cainfo: string|null, insecure: bool}|null $set
 *
 * @return array{cainfo: string|null, insecure: bool}
 */
function agent_probe_tls(?array $set = null): array
{
    static $tls = array('cainfo' => null, 'insecure' => false);
    if ($set !== null) {
        $tls = $set;
    }

    return $tls;
}

/** A bundle of certificate authorities in the usual places, for a PHP that has none configured (the Windows builds have none). */
function agent_probe_find_bundle(): ?string
{
    if ((string) ini_get('curl.cainfo') !== '' || (string) ini_get('openssl.cafile') !== '') {
        return null;   // configured: the default is right
    }
    $candidates = array(
        'C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt', 'C:/Program Files (x86)/Git/mingw64/etc/ssl/certs/ca-bundle.crt',
        '/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/cert.pem', '/usr/local/share/certs/ca-root-nss.crt',
    );
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/** @return array{status: int, headers: array<string, string>, body: string, error: string, seconds: float} */
function agent_probe_http(string $method, string $url, array $headers = array(), string $body = '', int $timeout = 30, string $userAgent = 'curl-like/1.0')
{
    $started = microtime(true);
    $lines = array('User-Agent: ' . $userAgent, 'Accept-Encoding: identity');
    foreach ($headers as $name => $value) {
        $lines[] = $name . ': ' . $value;
    }
    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        $received = array();
        curl_setopt_array($handle, array(
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $lines, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($h, $line) use (&$received) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ));
        curl_setopt($handle, CURLOPT_ENCODING, 'identity');
        $tls = agent_probe_tls();
        if ($tls['insecure']) {
            curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 0);
        } elseif ($tls['cainfo'] !== null) {
            curl_setopt($handle, CURLOPT_CAINFO, $tls['cainfo']);
        }
        if ($body !== '') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $text = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = $text === false ? curl_error($handle) : '';

        return array('status' => $status, 'headers' => $received, 'body' => $text === false ? '' : (string) $text, 'error' => $error, 'seconds' => microtime(true) - $started);
    }
    $tls = agent_probe_tls();
    $ssl = $tls['insecure'] ? array('verify_peer' => false, 'verify_peer_name' => false) : ($tls['cainfo'] !== null ? array('cafile' => $tls['cainfo']) : array());
    $context = stream_context_create(array(
        'http' => array('method' => $method, 'header' => implode("\r\n", $lines), 'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0),
        'ssl' => $ssl,
    ));
    $text = @file_get_contents($url, false, $context);
    $received = array();
    $status = 0;
    $defined = get_defined_vars();   // not by its name: PHP 8.5 deprecates the variable and offers a function
    $responseHeaders = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : (isset($defined['http_response_header']) ? $defined['http_response_header'] : array());
    foreach ($responseHeaders === null ? array() : $responseHeaders as $line) {
        if (preg_match('~^HTTP/\S+ (\d+)~', $line, $m) === 1) {
            $status = (int) $m[1];
        } elseif (strpos($line, ':') !== false) {
            $parts = explode(':', $line, 2);
            $received[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }

    return array('status' => $status, 'headers' => $received, 'body' => $text === false ? '' : (string) $text, 'error' => $text === false && $status === 0 ? 'no answer' : '', 'seconds' => microtime(true) - $started);
}

/** Remembers the token, so that nothing that is printed can show it (a host's error page may repeat the address that was asked for). */
function agent_probe_secret(?string $set = null): string
{
    static $secret = '';
    if ($set !== null) {
        $secret = $set;
    }

    return $secret;
}

/** The first words of a page that a host or a firewall answered with: no markup, no style sheet. */
function agent_probe_words(string $html): string
{
    $html = preg_replace('~<(style|script)\b.*?</\1>~is', ' ', $html);

    $words = substr(trim(preg_replace('~\s+~', ' ', strip_tags((string) $html))), 0, 120);

    return agent_probe_secret() === '' ? $words : str_replace(agent_probe_secret(), '***', $words);
}

function agent_probe_say(string $level, string $text): void
{
    echo str_pad($level, 8) . (agent_probe_secret() === '' ? $text : str_replace(agent_probe_secret(), '***', $text)) . "\n";
}

/**
 * @param array<string, string> $options
 */
function agent_probe_check(string $url, array $options): int
{
    $tokenFile = isset($options['token-file']) ? $options['token-file'] : 'agent-probe.token';
    $token = is_file($tokenFile) ? trim((string) file_get_contents($tokenFile)) : '';
    if ($token === '') {
        fwrite(STDERR, "No token: run `php agent-probe.php make` first (or give --token-file=FILE).\n");
        return 2;
    }
    agent_probe_secret($token);
    agent_probe_tls(array('cainfo' => isset($options['cainfo']) ? $options['cainfo'] : agent_probe_find_bundle(), 'insecure' => isset($options['insecure'])));
    if (isset($options['insecure'])) {
        agent_probe_say('note', 'The certificate of the host is NOT checked (--insecure).');
    } elseif (agent_probe_tls()['cainfo'] !== null && !isset($options['cainfo'])) {
        agent_probe_say('info', 'This PHP has no bundle of certificate authorities; the probe uses ' . agent_probe_tls()['cainfo'] . '.');
    }
    $url = preg_replace('~[?#].*$~', '', $url);
    $q = $url . '?t=' . rawurlencode($token);
    $mb = isset($options['size']) ? max(1, (int) $options['size']) : 20;
    $long = isset($options['long']) ? max(0, (int) $options['long']) : 40;
    $problems = 0;
    $time = time();
    $nonce = bin2hex(random_bytes(12));
    $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', 'probe', 'probe', true)), '+/', '-_'), '=');
    $signed = array('X-ThreadSafe-Time' => (string) $time, 'X-ThreadSafe-Nonce' => $nonce, 'X-ThreadSafe-Signature' => $signature);

    echo "Probe of " . preg_replace('~^(https?://[^/]+).*$~', '$1', $url) . "/... (the path and the token are not printed)\n\n";

    // --- 0. reachable at all, a redirect in front?
    $first = agent_probe_http('GET', $q . '&a=echo');
    if ($first['status'] >= 300 && $first['status'] < 400) {
        agent_probe_say('PROBLEM', 'The URL answers with a redirect (HTTP ' . $first['status'] . ') to ' . (isset($first['headers']['location']) ? preg_replace('~\?.*$~', '?...', $first['headers']['location']) : '?') . '. Use the final address: a POST that is redirected becomes a GET.');
        return 1;
    }
    $decoded = json_decode($first['body'], true);
    if ($first['status'] === 0 && preg_match('~certificate|SSL~i', $first['error']) === 1) {
        agent_probe_say('PROBLEM', 'The certificate of the host cannot be checked by this PHP (' . $first['error'] . '). That is a matter of this machine, not of the host: give --cainfo=FILE (a bundle of certificate authorities) or, for this probe only, --insecure.');
        return 1;
    }
    if ($first['status'] !== 200 || !is_array($decoded)) {
        agent_probe_say('PROBLEM', 'No answer from the probe: HTTP ' . $first['status'] . ($first['error'] !== '' ? ' (' . $first['error'] . ')' : '') . '. Is the file there, the URL right, the token the one of this copy? A host page or a firewall page instead of "Not found" would be a finding in itself: ' . agent_probe_words($first['body']));
        return 1;
    }
    agent_probe_say('ok', 'The probe answers. PHP ' . $decoded['php'] . ' (' . $decoded['sapi'] . '), ' . $decoded['software'] . '. It runs in: ' . $decoded['directory']);
    agent_probe_say('', 'HTTPS: ' . ($decoded['https'] ? 'yes' : 'NO - the agent refuses a plain-http request; use https'));
    $l = $decoded['limits'];
    agent_probe_say('info', 'max_execution_time ' . $l['max_execution_time'] . ', memory_limit ' . $l['memory_limit'] . ', post_max_size ' . $l['post_max_size'] . ', open_basedir ' . ($l['open_basedir'] === '' ? '(none)' : $l['open_basedir']));
    if ($l['disable_functions'] !== '') {
        agent_probe_say('info', 'disable_functions: ' . $l['disable_functions']);
    }
    agent_probe_say('info', 'free space here: ' . ($decoded['freeBytes'] === null ? 'unknown' : round($decoded['freeBytes'] / 1073741824, 1) . ' GB') . '; sodium ' . ($decoded['extensions']['sodium'] ? 'yes' : 'NO') . ', zlib ' . ($decoded['extensions']['zlib'] ? 'yes' : 'NO'));

    // --- 1. the headers
    echo "\n1. Custom headers\n";
    $r = agent_probe_http('GET', $q . '&a=echo', $signed);
    $d = json_decode($r['body'], true);
    if (!is_array($d)) {
        agent_probe_say('PROBLEM', 'A request with X-ThreadSafe-* headers got HTTP ' . $r['status'] . ' and no answer of the probe: the host or a firewall refuses it. ' . agent_probe_words($r['body']));
        ++$problems;
    } else {
        $ok = $d['ts']['time'] === (string) $time && $d['ts']['nonce'] === $nonce && $d['ts']['signature'] === $signature;
        agent_probe_say($ok ? 'ok' : 'PROBLEM', $ok ? 'X-ThreadSafe-Time, -Nonce and -Signature arrive unchanged.' : 'The headers did not arrive as sent (time ' . var_export($d['ts']['time'] !== null, true) . ', nonce ' . var_export($d['ts']['nonce'] !== null, true) . ', signature ' . var_export($d['ts']['signature'] !== null, true) . '). Decision 5: the values travel in the query string.');
        $problems += $ok ? 0 : 1;
        $fallback = agent_probe_http('GET', $q . '&a=echo&ts_time=' . $time . '&ts_nonce=' . $nonce . '&ts_signature=' . $signature);
        $fd = json_decode($fallback['body'], true);
        agent_probe_say(is_array($fd) && $fd['query']['signature'] === $signature ? 'ok' : 'note', 'The same values in the query string ' . (is_array($fd) && $fd['query']['signature'] === $signature ? 'arrive.' : 'did not arrive (HTTP ' . $fallback['status'] . ').'));
        $auth = agent_probe_http('GET', $q . '&a=echo', array('Authorization' => 'Basic cHJvYmU6cHJvYmU='));
        $ad = json_decode($auth['body'], true);
        agent_probe_say('info', 'An Authorization header ' . (is_array($ad) && $ad['authorization'] ? 'arrives in PHP.' : 'does NOT arrive in PHP (the REST API of WordPress with application passwords would not work here; the plan does not use it).'));
        agent_probe_say('info', 'Header names that reached PHP: ' . implode(', ', array_slice($d['headerNames'], 0, 20)));
    }

    // --- 2. a POST with a body and a long query
    echo "\n2. A POST with a body, a long path\n";
    $payload = str_repeat('0123456789abcdef', 12800);   // 200 KB
    $pad = '&pad=' . str_repeat('x', 1500);
    $p = agent_probe_http('POST', $q . '&a=echo' . $pad, $signed + array('Content-Type' => 'application/json'), $payload);
    $pd = json_decode($p['body'], true);
    if (!is_array($pd)) {
        agent_probe_say('PROBLEM', 'The POST (200 KB, a query of 1500 characters) got HTTP ' . $p['status'] . ': ' . agent_probe_words($p['body']));
        ++$problems;
    } else {
        $ok = $pd['bodySha256'] === hash('sha256', $payload) && $pd['uriLength'] > 1500;
        agent_probe_say($ok ? 'ok' : 'PROBLEM', $ok ? 'The body arrives whole (200 KB, SHA-256 equal) and the long path too.' : 'The body or the path was changed on the way.');
        $problems += $ok ? 0 : 1;
    }

    // --- 8. another user agent
    echo "\n3. The user agent\n";
    $u = agent_probe_http('GET', $q . '&a=echo', $signed, '', 30, 'ThreadSafe-Agent/1.0');
    $ud = json_decode($u['body'], true);
    agent_probe_say(is_array($ud) ? 'ok' : 'PROBLEM', is_array($ud) ? 'A client that calls itself ThreadSafe-Agent is treated like any other.' : 'A client that calls itself ThreadSafe-Agent got HTTP ' . $u['status'] . ' (a firewall that refuses unknown user agents?).');
    $problems += is_array($ud) ? 0 : 1;

    // --- 6. the clock
    echo "\n4. The clock\n";
    $before = microtime(true);
    $c = agent_probe_http('GET', $q . '&a=echo');
    $after = microtime(true);
    $cd = json_decode($c['body'], true);
    if (is_array($cd)) {
        $difference = $cd['hostTime'] - ($before + $after) / 2;
        $ok = abs($difference) < 60;
        agent_probe_say($ok ? 'ok' : 'PROBLEM', 'The clock of the host differs by ' . round($difference, 1) . ' s (the signature has a window of 120 s' . ($ok ? '' : '; every request would be refused') . ').');
        $problems += $ok ? 0 : 1;
    }

    // --- 4. Range and the transfer
    echo "\n5. Resumable transfer (Range), $mb MB\n";
    $total = $mb * 1048576;
    $whole = agent_probe_http('GET', $q . '&a=file&mb=' . $mb, array(), '', 120);
    $started = $whole['seconds'];
    $correct = $whole['status'] === 200 && strlen($whole['body']) === $total && $whole['body'] === agent_probe_content(0, $total);
    agent_probe_say($correct ? 'ok' : 'PROBLEM', $correct ? 'The file arrives whole and correct in ' . round($started, 1) . ' s (' . round($mb / max(0.01, $started), 1) . ' MB/s).' : 'The file did not arrive whole: HTTP ' . $whole['status'] . ', ' . strlen($whole['body']) . ' of ' . $total . ' bytes' . ($whole['error'] !== '' ? ' (' . $whole['error'] . ')' : '') . '.');
    $problems += $correct ? 0 : 1;
    if (isset($whole['headers']['content-encoding']) && $whole['headers']['content-encoding'] !== 'identity') {
        agent_probe_say('PROBLEM', 'The answer is compressed behind our back (Content-Encoding: ' . $whole['headers']['content-encoding'] . '): a Range then counts compressed bytes. The host must not compress this address.');
        ++$problems;
    }
    $offset = intdiv($total, 3);
    $etag = isset($whole['headers']['etag']) ? $whole['headers']['etag'] : '';
    $part = agent_probe_http('GET', $q . '&a=file&mb=' . $mb, array('Range' => 'bytes=' . $offset . '-', 'If-Range' => $etag), '', 120);
    $rangeOk = $part['status'] === 206 && $part['body'] === agent_probe_content($offset, $total - $offset) && isset($part['headers']['content-range']);
    agent_probe_say($rangeOk ? 'ok' : 'PROBLEM', $rangeOk ? 'A request from byte ' . $offset . ' answers 206 with the right Content-Range and the right bytes: a cut transfer can go on.' : 'Range did not work: HTTP ' . $part['status'] . ($part['status'] === 200 ? ' (the whole file again: the host or a cache ignores Range)' : '') . '.');
    $problems += $rangeOk ? 0 : 1;
    $stale = agent_probe_http('GET', $q . '&a=file&mb=' . $mb, array('Range' => 'bytes=' . $offset . '-', 'If-Range' => '"stale"'), '', 120);
    agent_probe_say($stale['status'] === 200 ? 'ok' : 'note', 'If-Range with a wrong ETag answers HTTP ' . $stale['status'] . ($stale['status'] === 200 ? ' (the whole file, as it should).' : '.'));

    // --- 3. how long may one request run
    echo "\n6. How long may a request run\n";
    if ($long === 0) {
        agent_probe_say('skipped', '--long=0');
    } else {
        $reached = 0;
        for ($s = 10; $s <= $long; $s += 10) {
            $x = agent_probe_http('GET', $q . '&a=sleep&s=' . $s, array(), '', $s + 20);
            $xd = json_decode($x['body'], true);
            if (is_array($xd) && $xd['slept'] >= $s - 1) {
                $reached = $s;
                agent_probe_say('ok', "A request of $s s ran through.");
                continue;
            }
            agent_probe_say('note', "A request of $s s was cut after " . round($x['seconds'], 1) . ' s: HTTP ' . $x['status'] . ($x['error'] !== '' ? ' (' . $x['error'] . ')' : '') . ' ' . agent_probe_words($x['body']));
            break;
        }
        agent_probe_say('info', 'The longest request that ran through: ' . $reached . ' s. The agent takes a budget of about two thirds of it per step (20 s at most).');
    }

    if (isset($options['remove'])) {
        $gone = json_decode(agent_probe_http('GET', $q . '&a=remove')['body'], true);
        agent_probe_say(is_array($gone) && $gone['removed'] ? 'ok' : 'note', is_array($gone) && $gone['removed'] ? 'The probe file on the host is deleted.' : 'The probe file could not be deleted by itself: delete it by hand.');
    } else {
        echo "\nDelete the probe file on the host when you are done (or run the check again with --remove).\n";
    }
    echo "\n" . ($problems === 0 ? 'No problem found.' : $problems . ' problem(s) found.') . " Send this output (it holds no secret) for the findings of docs/PHASE13.md section 8.\n";

    return $problems === 0 ? 0 : 1;
}
