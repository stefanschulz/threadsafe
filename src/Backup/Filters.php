<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

use InvalidArgumentException;

/**
 * Which paths of a site a backup leaves out.
 *
 * A pattern is relative to the site root and uses the slash as separator:
 *
 * - `var/cache`: that path and everything below it
 * - `/cache`: a leading slash anchors a single name at the site root (without it, a name with no slash matches at any depth, see below):
 *   the directory `cache` of the site, not `plugins/system/cache` or `libraries/vendor/psr/cache`, which are code
 * - `*.log`: a name without a slash matches the last path segment at any depth
 * - a leading `**` followed by a slash: the rest of the pattern may start at any depth (for example `.git` anywhere)
 * - `*` stands for any characters except the slash, `**` for any characters including it, `?` for one character
 * - a trailing slash (`cache/`): directories only
 *
 * There is no negation. A pattern that matches a directory excludes the whole tree: the scanner does not even enter it.
 * Literal paths (the storage directory, the program's own data) are excluded exactly, without pattern characters.
 */
final class Filters
{
    /** @var list<array{string, bool, bool}> regular expression, directories only, matches the last segment only */
    private array $rules = [];

    /** @var array<string, true> */
    private array $literals = [];

    /**
     * @param list<string> $patterns
     * @param list<string> $literalPaths relative paths that are excluded exactly, whatever characters they contain
     *
     * @throws InvalidArgumentException a pattern is not valid
     */
    public function __construct(array $patterns = [], array $literalPaths = [])
    {
        foreach ($patterns as $pattern) {
            $problem = self::problem($pattern);
            if ($problem !== null) {
                throw new InvalidArgumentException("Invalid filter \"$pattern\": $problem.");
            }
            $this->rules[] = self::compile($pattern);
        }
        foreach ($literalPaths as $path) {
            $this->literals[trim($path, '/')] = true;
        }
    }

    /**
     * The path of $absolute relative to $root if it lies strictly inside it (symbolic links resolved), otherwise null. For
     * excluding the storage directory and the program's own data, which may or may not be inside the site.
     */
    public static function relativeInside(string $root, string $absolute): ?string
    {
        $base = realpath($root);
        $target = realpath($absolute);
        if ($base === false || $target === false) {
            return null;
        }
        $base = rtrim(str_replace('\\', '/', $base), '/') . '/';
        $target = str_replace('\\', '/', $target);

        return str_starts_with($target . '/', $base) && strlen($target) + 1 > strlen($base) ? trim(substr($target . '/', strlen($base)), '/') : null;
    }

    /** What is wrong with a pattern, or null if it is fine. */
    public static function problem(string $pattern): ?string
    {
        if (trim($pattern) === '' || trim($pattern, '/') === '') {
            return 'empty';
        }
        if (str_contains($pattern, "\0") || str_contains($pattern, '\\')) {
            return 'NUL and backslashes are not allowed';
        }
        if (str_starts_with($pattern, '//')) {
            return 'a pattern may start with one slash (anchored at the site root), not with two';
        }
        foreach (explode('/', trim($pattern, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return 'empty, "." and ".." segments are not allowed';
            }
        }

        return null;
    }

    /**
     * True if the path is excluded.
     *
     * @param string $relative    path from the site root, no leading slash
     * @param bool   $isDirectory whether it is a directory (a pattern with a trailing slash only matches directories)
     */
    public function excludes(string $relative, bool $isDirectory): bool
    {
        if (isset($this->literals[$relative])) {
            return true;
        }
        $slash = strrpos($relative, '/');
        $last = $slash === false ? $relative : substr($relative, $slash + 1);
        foreach ($this->rules as [$regex, $directoriesOnly, $lastSegmentOnly]) {
            if ($directoriesOnly && !$isDirectory) {
                continue;
            }
            if (preg_match($regex, $lastSegmentOnly ? $last : $relative) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{string, bool, bool}
     */
    private static function compile(string $pattern): array
    {
        $directoriesOnly = str_ends_with($pattern, '/');
        $pattern = rtrim($pattern, '/');
        // a leading slash anchors the pattern at the site root: a single name is then that path, not a name at any depth
        $anchored = str_starts_with($pattern, '/');
        $pattern = ltrim($pattern, '/');
        $lastSegmentOnly = !$anchored && !str_contains($pattern, '/');

        $anywhere = false;
        if (str_starts_with($pattern, '**/')) {
            $anywhere = true;
            $pattern = substr($pattern, 3);
        }

        $regex = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; ++$i) {
            $c = $pattern[$i];
            if ($c === '*') {
                if (($pattern[$i + 1] ?? '') === '*') {
                    $regex .= '.*';
                    ++$i;
                } else {
                    $regex .= '[^/]*';
                }
            } elseif ($c === '?') {
                $regex .= '[^/]';
            } else {
                $regex .= preg_quote($c, '~');
            }
        }

        return ['~^' . ($anywhere ? '(?:.*/)?' : '') . $regex . '$~su', $directoriesOnly, $lastSegmentOnly && !$anywhere];
    }
}
