<?php

declare(strict_types=1);

namespace ThreadSafe\Backup;

use InvalidArgumentException;
use ThreadSafe\Archive\ZipFormat;
use ThreadSafe\Engine\Budget;

/**
 * Walks the directory tree of a site and says what goes into the backup, in a fixed order, in steps that can be continued.
 *
 * - **Order:** depth first, the names of a directory sorted bytewise, a directory's own entry before its contents. The
 *   same tree always gives the same sequence, so archives are reproducible.
 * - **Resumable:** the state is a stack of `(directory, last name done)` frames - small, plain data. A call re-reads the
 *   directories on the stack, so files that appeared or vanished between two calls are simply seen or not seen.
 * - **Filters:** an excluded directory is not entered; it makes no entry and no warning.
 * - **What is skipped, with a warning:** symbolic links (never followed), sockets, devices and other special files,
 *   unreadable directories, names that are not valid UTF-8 or that a ZIP archive must not hold (backslashes ...), files
 *   that disappear while the tree is walked.
 *
 * Directories get their own entries, so that empty directories and the permissions of directories survive.
 */
final class Scanner
{
    private const int S_IFMT = 0170000;

    private const int S_IFREG = 0100000;

    private const int S_IFDIR = 0040000;

    private const int S_IFLNK = 0120000;

    private readonly string $root;

    /**
     * @throws InvalidArgumentException the root is not a readable directory
     */
    public function __construct(string $root, private readonly Filters $filters)
    {
        $real = realpath($root);
        if ($real === false || !is_dir($real) || !is_readable($real)) {
            throw new InvalidArgumentException("The site path \"$root\" is not a readable directory.");
        }
        $this->root = rtrim($real, '/\\');
    }

    /**
     * The state to begin with.
     *
     * @return array<string, mixed>
     */
    public function start(): array
    {
        return ['frames' => [['dir' => '', 'after' => null]], 'visited' => 0, 'done' => false];
    }

    /**
     * The next items, up to $maxItems and while the budget lasts. Always makes progress: at least one directory entry is
     * read per call.
     *
     * @param array<string, mixed> $state a state from {@see start()} or from an earlier batch
     */
    public function next(array $state, Budget $budget, int $maxItems = 500): ScanBatch
    {
        $frames = $this->frames($state);
        $visited = is_int($state['visited'] ?? null) ? $state['visited'] : 0;
        $items = [];
        $warnings = [];
        /** @var array<string, list<string>|false> $listings */
        $listings = [];

        while ($frames !== [] && count($items) < $maxItems && ($items === [] || !$budget->exhausted())) {
            $top = array_key_last($frames);
            $directory = $frames[$top]['dir'];
            $after = $frames[$top]['after'];

            $listings[$directory] ??= $this->list($directory);
            $names = $listings[$directory];
            if ($names === false) {
                if (is_dir($this->absolute($directory))) {
                    $warnings[] = "Cannot read the directory: /$directory";
                }
                array_pop($frames);
                continue;
            }

            $next = null;
            foreach ($names as $name) {
                if ($after === null || strcmp($name, $after) > 0) {
                    $next = $name;
                    break;
                }
            }
            if ($next === null) {
                array_pop($frames);
                continue;
            }
            $frames[$top]['after'] = $next;
            ++$visited;

            $relative = $directory === '' ? $next : $directory . '/' . $next;
            $item = $this->examine($relative, $next, $warnings);
            if ($item === null) {
                continue;
            }
            $items[] = $item;
            if ($item->isDirectory) {
                $frames[] = ['dir' => $relative, 'after' => null];
            }
        }

        $done = $frames === [];

        return new ScanBatch($items, ['frames' => $frames, 'visited' => $visited, 'done' => $done], $warnings, $done);
    }

    /**
     * @param list<string> $warnings
     */
    private function examine(string $relative, string $name, array &$warnings): ?ScanItem
    {
        if (preg_match('//u', $relative) !== 1) {
            $warnings[] = 'Skipped, the name is not valid UTF-8: /' . addcslashes($relative, "\0..\37\177..\377");

            return null;
        }
        $absolute = $this->absolute($relative);
        $stat = @lstat($absolute);
        if ($stat === false) {
            $warnings[] = "Skipped, it disappeared while the site was scanned: /$relative";

            return null;
        }
        $type = $stat['mode'] & self::S_IFMT;
        $isDirectory = $type === self::S_IFDIR;

        if ($this->filters->excludes($relative, $isDirectory)) {
            return null;
        }
        if ($type === self::S_IFLNK) {
            $target = @readlink($absolute);
            $warnings[] = "Skipped the symbolic link /$relative" . ($target !== false ? " -> $target" : '');

            return null;
        }
        if ($type !== self::S_IFREG && !$isDirectory) {
            $warnings[] = "Skipped, neither a file nor a directory: /$relative";

            return null;
        }
        $reason = ZipFormat::unsafeReason($relative);
        if ($reason !== null) {
            $warnings[] = "Skipped, the name cannot go into an archive ($reason): /$relative";

            return null;
        }

        return new ScanItem($relative, $isDirectory, $isDirectory ? 0 : $stat['size'], $stat['mtime'], $stat['mode']);
    }

    /**
     * @return list<string>|false the names in the directory sorted bytewise, false if it cannot be read
     */
    private function list(string $directory): array|false
    {
        $names = @scandir($this->absolute($directory), SCANDIR_SORT_NONE);
        if ($names === false) {
            return false;
        }
        $names = array_values(array_filter($names, static fn (string $n): bool => $n !== '.' && $n !== '..'));
        sort($names, SORT_STRING);

        return $names;
    }

    private function absolute(string $relative): string
    {
        return $relative === '' ? $this->root : $this->root . '/' . $relative;
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return list<array{dir: string, after: string|null}>
     */
    private function frames(array $state): array
    {
        $frames = [];
        $raw = $state['frames'] ?? null;
        if (!is_array($raw)) {
            throw new InvalidArgumentException('The scanner state is invalid.');
        }
        foreach ($raw as $frame) {
            if (!is_array($frame) || !is_string($frame['dir'] ?? null) || !(is_string($frame['after'] ?? null) || ($frame['after'] ?? null) === null)
                || in_array('..', explode('/', $frame['dir']), true) || str_starts_with($frame['dir'], '/')) {
                throw new InvalidArgumentException('The scanner state is invalid.');
            }
            $frames[] = ['dir' => $frame['dir'], 'after' => $frame['after'] ?? null];
        }

        return $frames;
    }
}
