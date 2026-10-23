<?php
// Asks a Joomla site for its front page, the administrator login and the API, and prints the status lines.
$base = $argv[1] ?? 'http://localhost';
foreach (['/', '/administrator/', '/api/index.php/v1/content/articles'] as $path) {
    $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 30]]);
    $body = @file_get_contents($base . $path, false, $context);
    $status = $http_response_header[0] ?? 'no answer';
    echo $path, ' -> ', $status, ' (', strlen((string) $body), " bytes)\n";
    if ($path === '/' && preg_match('~<meta name="generator"[^>]*>~', (string) $body, $m)) {
        echo '   ', $m[0], "\n";
    }
    if ($path === '/' && preg_match('~<title>[^<]*</title>~', (string) $body, $m)) {
        echo '   ', $m[0], "\n";
    }
}
