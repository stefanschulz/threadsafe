<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9;

/**
 * Fixes absolute paths in a `php.ini` / `.user.ini` that belong to the old server (e.g. `error_log =
 * /kunden/homepages/22/d123/htdocs/shop/var/logs/php-errors.log`).
 *
 * The old server's directory layout is unknown, so the new path is found from the end: the longest tail of the old
 * path that exists below the shop root on this server. Paths that already lie inside the shop are left alone, and
 * paths without a usable tail are only reported.
 */
final class PhpIniRewriter
{
    /**
     * @param list<string> $directives directives whose value is a single path
     * @param string       $localRoot  the shop root as this process sees it (to check which tails exist)
     * @param string       $serverRoot the shop root as the web server sees it (what the new paths start with)
     * @param list<string> $knownDirectories directories (relative to the root) that exist or are about to be created
     */
    public function __construct(
        private readonly array $directives,
        private readonly string $localRoot,
        private readonly string $serverRoot,
        private readonly array $knownDirectories = [],
    ) {
    }

    public function rewrite(string $content): PhpIniRewrite
    {
        $names = implode('|', array_map(static fn (string $d): string => preg_quote($d, '~'), $this->directives));
        $changes = [];
        $warnings = [];

        $lines = explode("\n", $content);
        foreach ($lines as $index => $line) {
            if (preg_match('~^(\s*(?:' . $names . ')\s*=\s*)(["\']?)(/[^"\'\s;]*)(\2)(.*)$~', $line, $m) !== 1) {
                continue;
            }
            [, $head, $quote, $path, , $tail] = $m;
            $directive = trim(explode('=', $head, 2)[0]);

            if (str_starts_with($path, $this->serverRoot . '/') || $path === $this->serverRoot) {
                continue;
            }
            $new = $this->map($path);
            if ($new === null) {
                $warnings[] = "$directive = $path points outside the shop and no matching folder was found below it; adjust it by hand.";
                continue;
            }
            $lines[$index] = $head . $quote . $new . $quote . $tail;
            $changes[] = ['directive' => $directive, 'before' => $path, 'after' => $new];
        }

        return new PhpIniRewrite(implode("\n", $lines), $changes, $warnings);
    }

    /** The new location of an old path, or null if its tail cannot be found below the shop root. */
    private function map(string $path): ?string
    {
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
        for ($i = 0, $count = count($segments); $i < $count; ++$i) {
            $tail = implode('/', array_slice($segments, $i));
            $parent = dirname($tail);
            $parentExists = $parent !== '.' && (in_array($parent, $this->knownDirectories, true) || is_dir($this->localRoot . '/' . $parent));
            $tailExists = in_array($tail, $this->knownDirectories, true) || file_exists($this->localRoot . '/' . $tail);
            if ($parentExists || $tailExists) {
                return $this->serverRoot . '/' . $tail;
            }
        }

        return null;
    }
}
