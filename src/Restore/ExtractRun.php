<?php

declare(strict_types=1);

namespace ThreadSafe\Restore;

/**
 * What {@see Extractor} changes during one tick: the position in the archive, the counters, and the helpers that look at the
 * target directory. Made from the saved state, turned back into it at the end of the tick.
 */
final class ExtractRun
{
    private const int MAX_WARNINGS = 50;

    public int $part;
    public int $index;
    public int $position;
    public ?int $current;
    public int $files;
    public int $databaseFiles;
    public int $directories;
    public int $bytes;
    public int $ignored;

    /** @var list<string> */
    public array $warnings;

    public readonly string $root;
    public readonly string $database;

    /** The name of the archive entry in progress, for messages. */
    public string $entry = '';

    /** @var list<string> */
    private readonly array $protected;

    /** @var array<string, true> directories below the root that were looked at in this tick and are real directories */
    private array $verified = [];

    /** @var list<int>|null user and group of the root directory when this process may give them away (root), [] when not */
    private ?array $owner = null;

    /**
     * @param array<string, mixed> $state
     */
    public function __construct(array $state)
    {
        $this->part = (int) $state['part'];
        $this->index = (int) $state['index'];
        $this->position = (int) $state['position'];
        $this->current = $state['current'] === null ? null : (int) $state['current'];
        $this->files = (int) $state['files'];
        $this->databaseFiles = (int) $state['databaseFiles'];
        $this->directories = (int) $state['directories'];
        $this->bytes = (int) $state['bytes'];
        $this->ignored = (int) $state['ignored'];
        /** @var list<string> $warnings */
        $warnings = $state['warnings'];
        $this->warnings = $warnings;
        $this->root = (string) $state['root'];
        $this->database = (string) $state['database'];
        /** @var list<string> $protected */
        $protected = $state['protected'];
        $this->protected = $protected;
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    public function toState(array $state): array
    {
        return [...$state, 'part' => $this->part, 'index' => $this->index, 'position' => $this->position, 'current' => $this->current,
            'files' => $this->files, 'databaseFiles' => $this->databaseFiles, 'directories' => $this->directories, 'bytes' => $this->bytes, 'ignored' => $this->ignored, 'warnings' => $this->warnings];
    }

    public function warn(string $message): void
    {
        if (count($this->warnings) < self::MAX_WARNINGS) {
            $this->warnings[] = $message;
        } elseif (count($this->warnings) === self::MAX_WARNINGS) {
            $this->warnings[] = 'More warnings are not listed.';
        }
    }

    /** A message prefix that names the entry in progress, e.g. `Cannot write "files/index.php"`. */
    public function describe(string $what): string
    {
        return $what . ' "' . addcslashes(mb_strcut($this->entry, 0, 200), "\0..\37\177") . '"';
    }

    public function isProtected(string $relative): bool
    {
        foreach ($this->protected as $path) {
            if ($path !== '' && ($relative === $path || str_starts_with($relative, $path . '/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gives a file or directory that was just made the owner and group of the root directory, when the restore runs as root: files
     * made by root in a directory of the web server's user would be of no use to the web server. Anyone else cannot change the owner,
     * and what they create already belongs to them.
     */
    public function adopt(string $path): void
    {
        if ($this->owner === null) {
            $owner = function_exists('posix_geteuid') && posix_geteuid() === 0 ? @fileowner($this->root) : false;
            $group = $owner === false ? false : @filegroup($this->root);
            $this->owner = $owner === false || $group === false ? [] : [$owner, $group];
        }
        if ($this->owner !== []) {
            @chown($path, $this->owner[0]);
            @chgrp($path, $this->owner[1]);
        }
    }

    /** A path below the root, for messages. */
    public function relative(string $path): string
    {
        return str_starts_with($path, $this->root . '/') ? substr($path, strlen($this->root) + 1) : $path;
    }

    /**
     * Makes sure that a directory below the root exists, creating what is missing. Nothing on the way may be a symbolic link or
     * a file: the archive is not trusted to decide where a write lands.
     *
     * @param string $relative path below the root without leading or trailing slash; '' is the root itself
     *
     * @throws ExtractException
     */
    public function ensureDirectory(string $relative): void
    {
        if ($relative === '') {
            if (!is_dir($this->root) && !@mkdir($this->root, 0755, true) && !is_dir($this->root)) {
                throw new ExtractException("Cannot create the directory \"{$this->root}\".");
            }

            return;
        }
        if (isset($this->verified[$relative])) {
            return;
        }
        $this->ensureDirectory('');
        $path = $this->root;
        $walked = '';
        foreach (explode('/', $relative) as $segment) {
            $walked = $walked === '' ? $segment : $walked . '/' . $segment;
            $path .= '/' . $segment;
            if (isset($this->verified[$walked])) {
                continue;
            }
            if (is_link($path)) {
                throw new ExtractException($this->describe('Refusing') . ": /$walked is a symbolic link; the archive would write through it. The target is in a partial state.");
            }
            if (!is_dir($path)) {
                if (file_exists($path)) {
                    throw new ExtractException($this->describe('Cannot create') . ": /$walked is a file, not a directory. The target is in a partial state.");
                }
                if (!@mkdir($path, 0755) && !is_dir($path)) {
                    throw new ExtractException($this->describe('Cannot create') . ": the directory /$walked cannot be made. The target is in a partial state.");
                }
                $this->adopt($path);
            }
            $this->verified[$walked] = true;
        }
    }
}
