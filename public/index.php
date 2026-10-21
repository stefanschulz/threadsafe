<?php

/**
 * The only entry point of the web interface (docs/PHASE4.md 3.1). Point the document root of the web server at this directory;
 * everything else of ThreadSafe (src, data, plugins, vendor) then cannot be fetched at all. The interface is switched off until
 * `php bin/threadsafe admin enable` (or the setup in the browser) turns it on.
 */
declare(strict_types=1);

if (PHP_VERSION_ID < 80400) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ThreadSafe needs PHP 8.4 or newer (this is ' . PHP_VERSION . ").\n";
    exit;
}

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Dependencies are missing: run \"composer install\".\n";
    exit;
}
require $autoload;

ThreadSafe\Admin\Entry::run();
