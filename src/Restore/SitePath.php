<?php

declare(strict_types=1);

namespace ThreadSafe\Restore;

/**
 * Paths of a restored site for the links on the last page of the wizard.
 */
final class SitePath
{
    /** A base path as the person typed it ("shop", "/shop") in the form "/shop/"; the fallback if nothing was typed; "/" if neither is usable. */
    public static function base(string $typed, string $fallback = '/'): string
    {
        $path = trim($typed) !== '' ? trim($typed) : $fallback;
        $path = trim($path, '/');

        return $path === '' ? '/' : '/' . $path . '/';
    }

    private function __construct()
    {
    }
}
