<?php

declare(strict_types=1);

namespace ThreadSafe\Joomla;

/**
 * Brings the `.htaccess` of a Joomla site in line with the base path (the directory of the domain that the site lives in).
 *
 * The file is Joomla's `htaccess.txt` copied under that name. What depends on the base path is the line `RewriteBase` (commented out in
 * the original, because it is only needed when the address does not follow the directories) and the three conditions that anchor the
 * paths of the two applications at the root of the domain: `^/api/`, `!^/api/index\.php` and `!^/index\.php`. In a sub-directory the
 * API conditions never match unless they name it. Everything else is left as it is.
 *
 * Returns the new text and what was done, so that the step can report it.
 */
final class HtaccessRewriter
{
    /** the conditions that anchor at the root, as Joomla writes them (the part after `RewriteCond %{REQUEST_URI} `) */
    private const array ANCHORS = ['^%sapi/' => true, '!^%sapi/index\.php' => true, '!^%sindex\.php' => true];

    /**
     * @param string $base the new base path: `/` or `/dir/`
     *
     * @return array{content: string, changes: int, warnings: list<string>}
     */
    public function rewrite(string $content, string $base): array
    {
        $changes = 0;
        $warnings = [];
        $old = $this->currentBase($content);

        // RewriteBase: the line is made active for a sub-directory, and commented out (as Joomla ships it) for the root
        $wanted = $base === '/' ? '# RewriteBase /' : 'RewriteBase ' . $base;
        $found = false;
        $content = (string) preg_replace_callback(
            '/^([ \t]*)(#[ \t]*)?RewriteBase[ \t]+\S*[ \t]*(\r?)$/m',
            static function (array $m) use (&$found, &$changes, $wanted): string {
                if ($found) {
                    return $m[0];   // only the first one is Joomla's; a second would be the site's own
                }
                $found = true;
                $line = $m[1] . $wanted . $m[3];
                if ($line !== $m[0]) {
                    ++$changes;
                }

                return $line;
            },
            $content,
        );
        if (!$found && $base !== '/') {
            $warnings[] = 'The .htaccess has no RewriteBase line; if the search-engine friendly addresses do not work in ' . $base . ', add "RewriteBase ' . $base . '".';
        }

        // the anchors: from the base the file had to the new one
        if ($old !== $base) {
            foreach (array_keys(self::ANCHORS) as $pattern) {
                $from = sprintf($pattern, $old);
                $to = sprintf($pattern, $base);
                $count = 0;
                $content = str_replace('RewriteCond %{REQUEST_URI} ' . $from, 'RewriteCond %{REQUEST_URI} ' . $to, $content, $count);
                $changes += $count;
            }
        }

        return ['content' => $content, 'changes' => $changes, 'warnings' => $warnings];
    }

    /**
     * The base path that the file is written for: the active `RewriteBase`, or `/` where there is none (the paths are then anchored at
     * the root, as the original has them).
     */
    public function currentBase(string $content): string
    {
        if (preg_match('/^[ \t]*RewriteBase[ \t]+(\S+)[ \t]*\r?$/m', $content, $m) === 1) {
            return rtrim($m[1], '/') . '/';
        }

        return '/';
    }
}
