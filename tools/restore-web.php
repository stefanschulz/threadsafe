<?php

/**
 * Development entry of the restore wizard (the single-file threadsafe-restore.php of work package 7 is built from the same classes):
 *
 *   THREADSAFE_RESTORE_BASE=/some/dir php -S 127.0.0.1:8080 tools/restore-web.php
 *
 * The directory holds the backup parts and the key file threadsafe-restore-key.php; it is also where the site is restored to by default.
 */
declare(strict_types=1);

use ThreadSafe\Web\Request;
use ThreadSafe\Web\Wizard;

require dirname(__DIR__) . '/vendor/autoload.php';

$base = getenv('THREADSAFE_RESTORE_BASE');
if ($base === false || $base === '' || !is_dir($base)) {
    http_response_code(500);
    echo 'Set THREADSAFE_RESTORE_BASE to the directory with the backup and threadsafe-restore-key.php.';

    return;
}

(new Wizard($base))->handle(Request::fromGlobals())->send();
