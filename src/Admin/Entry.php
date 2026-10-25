<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use ThreadSafe\Core\Paths;
use ThreadSafe\Core\Version;
use ThreadSafe\Update\Migrations;
use ThreadSafe\Update\Migrator;
use ThreadSafe\Update\UpdateException;
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

        $request = Request::fromGlobals();
        // the static files first: they need neither the data directory nor a login
        $asset = (new Assets(Paths::program() . '/public', Version::CURRENT, Paths::home() !== null))->answer($request, $base);
        if ($asset !== null) {
            $asset->send();

            return;
        }

        // the first start of a new release brings the data to its format; data of a newer program is refused (docs/PHASE7.md 3.6)
        try {
            (new Migrator(Migrations::all()))->ensure(Paths::data());
        } catch (UpdateException $e) {
            View::error('The data was made by a newer ThreadSafe', $e->getMessage(), 500)->send();

            return;
        }

        (new Kernel(Paths::data(), $base))->handle($request)->send();
    }

    private function __construct()
    {
    }
}
