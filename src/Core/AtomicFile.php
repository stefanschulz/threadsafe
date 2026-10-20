<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

use RuntimeException;

/**
 * Writes files atomically (temporary file in the same directory, then rename), so a crash never leaves half a file,
 * and keeps who owns the file.
 *
 * An existing file keeps its permissions and, when ThreadSafe runs as root, its owner and group: a shop file
 * that suddenly belongs to root would stop working for the web server. A new file gets the mode 0644 and, as root,
 * the owner and group of its directory.
 */
final class AtomicFile
{
    public static function write(string $path, string $content): void
    {
        $exists = is_file($path);
        $mode = $exists ? (fileperms($path) & 0777) : 0644;
        $temporary = $path . '.threadsafe-' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($temporary, $content, LOCK_EX) === false) {
            throw new RuntimeException("Cannot write \"$temporary\".");
        }
        @chmod($temporary, $mode);
        self::adoptOwner($temporary, $exists ? $path : dirname($path));

        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException("Cannot replace \"$path\".");
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }

    /**
     * Gives $path the owner and group of $reference. Only has an effect for root; anyone else cannot change the
     * owner, and a file they create already belongs to them.
     */
    public static function adoptOwner(string $path, string $reference): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return;
        }
        $owner = @fileowner($reference);
        $group = @filegroup($reference);
        if ($owner !== false) {
            @chown($path, $owner);
        }
        if ($group !== false) {
            @chgrp($path, $group);
        }
    }
}
