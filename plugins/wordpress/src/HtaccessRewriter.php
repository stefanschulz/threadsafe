<?php

declare(strict_types=1);

namespace ThreadSafe\WordPress;

/**
 * Brings the `.htaccess` of a WordPress site in line with the base path (the directory of the domain that the site lives in).
 *
 * What WordPress writes between `# BEGIN WordPress` and `# END WordPress` names the base twice: `RewriteBase /` and the last rule
 * `RewriteRule . /index.php [L]`. Only those two lines inside that block are changed; the rules that other plugins wrote around it (caching,
 * security) are left as they are and, where they name the old path, reported (docs/PHASE9.md 3.5, step 6).
 *
 * Returns the new text and what was done, so that the step can report it.
 */
final class HtaccessRewriter
{
    private const string BEGIN = '# BEGIN WordPress';

    private const string END = '# END WordPress';

    /**
     * @param string $base the new base path: `/` or `/dir/`
     *
     * @return array{content: string, changes: int, warnings: list<string>}
     */
    public function rewrite(string $content, string $base): array
    {
        $begin = strpos($content, self::BEGIN);
        $end = $begin === false ? false : strpos($content, self::END, $begin);
        if ($begin === false || $end === false) {
            return ['content' => $content, 'changes' => 0, 'warnings' => $base === '/' ? [] : ['The .htaccess has no block "# BEGIN WordPress"; if the permalinks do not work in ' . $base . ', add "RewriteBase ' . $base . '" and send the rest to ' . $base . 'index.php.']];
        }
        $block = substr($content, $begin, $end - $begin);
        $old = $this->currentBase($block);
        $changes = 0;
        $warnings = [];

        $block = (string) preg_replace_callback(
            '/^([ \t]*RewriteBase[ \t]+)\S+([ \t]*\r?)$/m',
            static function (array $m) use ($base, &$changes): string {
                $line = $m[1] . $base . $m[2];
                if ($line !== $m[0]) {
                    ++$changes;
                }

                return $line;
            },
            $block,
            -1,
            $bases,
        );
        if ($bases === 0) {
            $warnings[] = 'The block of WordPress in .htaccess has no RewriteBase line; if the permalinks do not work in ' . $base . ', add "RewriteBase ' . $base . '".';
        }
        $block = (string) preg_replace_callback(
            '/^([ \t]*RewriteRule[ \t]+\.[ \t]+)\S*index\.php([ \t]+\[L\][ \t]*\r?)$/m',
            static function (array $m) use ($base, &$changes): string {
                $line = $m[1] . $base . 'index.php' . $m[2];
                if ($line !== $m[0]) {
                    ++$changes;
                }

                return $line;
            },
            $block,
        );
        $content = substr($content, 0, $begin) . $block . substr($content, $end);

        // what other plugins wrote outside the block and that names a path
        $outside = substr($content, 0, $begin) . substr($content, $end);
        if ($old !== $base && $old !== '/' && str_contains($outside, $old)) {
            $warnings[] = 'Rules of the .htaccess outside the block of WordPress name the old path ' . $old . '; they are left as they are.';
        }

        return ['content' => $content, 'changes' => $changes, 'warnings' => $warnings];
    }

    /** The base path that the block is written for: its `RewriteBase`, or `/` where there is none. */
    public function currentBase(string $content): string
    {
        if (preg_match('/^[ \t]*RewriteBase[ \t]+(\S+)[ \t]*\r?$/m', $content, $m) === 1) {
            return rtrim($m[1], '/') . '/';
        }

        return '/';
    }
}
