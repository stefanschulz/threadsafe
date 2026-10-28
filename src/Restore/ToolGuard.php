<?php

declare(strict_types=1);

namespace ThreadSafe\Restore;

use ThreadSafe\Core\AtomicFile;

/**
 * Keeps the restore tool reachable while the restored `.htaccess` is in place. The `.htaccess` of a restored site is meant for the site: a
 * security extension (Admin Tools of Akeeba, for one) refuses every direct request for a PHP file except `index.php`, and that includes
 * `restore.php`, the page that carries the restore through. From the moment the file is unpacked the next step answers "Access forbidden" and
 * the restore stops, with nothing left to press the button of deleting the tool with. So a block at the top of the file lets the tool through
 * (`RewriteRule ^restore\.php$ - [L]` ends the rewriting before any rule of the site can refuse it); the tool takes the block out again when it is
 * deleted.
 *
 * Only the rules of `mod_rewrite` are met this way, which is what such extensions write; a server that refuses PHP files in another way is
 * not. The block is made by {@see protect()} each time an `.htaccess` has been written, so that it is there at every step.
 */
final class ToolGuard
{
    public const string BEGIN = '# BEGIN ThreadSafe restore tool (taken out when the tool is deleted)';

    public const string END = '# END ThreadSafe restore tool';

    /**
     * Puts (or renews) the block at the top of the `.htaccess` of the root.
     *
     * @param list<string> $scripts paths of the tool's files below the root, which must stay reachable
     *
     * @return bool whether the file was changed; false if there is no `.htaccess` or nothing to protect
     */
    public static function protect(string $root, array $scripts): bool
    {
        $file = rtrim($root, '/\\') . '/.htaccess';
        $rules = [];
        foreach ($scripts as $script) {
            $script = trim(str_replace('\\', '/', $script), '/');
            if ($script !== '' && !str_contains($script, '..')) {
                $rules[] = 'RewriteRule ^' . self::quote($script) . '$ - [L]';
            }
        }
        if ($rules === [] || !is_file($file) || is_link($file)) {
            return false;
        }
        $content = @file_get_contents($file);
        if ($content === false) {
            return false;
        }
        $block = self::BEGIN . "\n<IfModule mod_rewrite.c>\nRewriteEngine On\n" . implode("\n", $rules) . "\n</IfModule>\n" . self::END . "\n";
        $plain = self::without($content);
        $new = $block . $plain;
        if ($new === $content) {
            return false;
        }
        AtomicFile::write($file, $new);

        return true;
    }

    /**
     * Takes the block out again.
     *
     * @return bool whether the file was changed
     */
    public static function release(string $root): bool
    {
        $file = rtrim($root, '/\\') . '/.htaccess';
        if (!is_file($file) || is_link($file)) {
            return false;
        }
        $content = @file_get_contents($file);
        if ($content === false || !str_contains($content, self::BEGIN)) {
            return false;
        }
        AtomicFile::write($file, self::without($content));

        return true;
    }

    /** The content without the block (and without the line break after it). */
    private static function without(string $content): string
    {
        $start = strpos($content, self::BEGIN);
        if ($start === false) {
            return $content;
        }
        $end = strpos($content, self::END, $start);
        if ($end === false) {
            return $content;
        }
        $end += strlen(self::END);
        if (($content[$end] ?? '') === "\r") {
            ++$end;
        }
        if (($content[$end] ?? '') === "\n") {
            ++$end;
        }

        return substr($content, 0, $start) . substr($content, $end);
    }

    /** A path as the pattern of a RewriteRule: the characters of a regular expression written literally. */
    private static function quote(string $path): string
    {
        return (string) preg_replace('~([.\\\\+*?\[\](){}|^$])~', '\\\\$1', $path);
    }

    private function __construct()
    {
    }
}
