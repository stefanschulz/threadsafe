<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use ThreadSafe\Core\Paths;
use ThreadSafe\Web\Request;

/**
 * What `public/index.php` does once the code is loaded: answer one request of the web interface. Nothing is printed except the
 * response (PHP's own error display is switched off, because a message can name paths).
 */
final class Entry
{
    public static function run(): void
    {
        @ini_set('display_errors', '0');
        $server = $_SERVER;
        $base = rtrim(str_replace('\\', '/', dirname((string) ($server['SCRIPT_NAME'] ?? '/index.php'))), '/');

        (new Kernel(Paths::data(), $base))->handle(Request::fromGlobals())->send();
    }

    private function __construct()
    {
    }
}
