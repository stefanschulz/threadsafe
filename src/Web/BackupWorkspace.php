<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

use RuntimeException;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Profile\ProfileStore;

/**
 * What the backup tool keeps next to itself (docs/PHASE12.md 3.1): `.threadsafe-backup/` with a `data-<random>/` directory laid out like the data
 * directory of the app (profiles, runs, sessions), so that the engine runs on it unchanged, and the **storage**, whose name carries 128 random
 * bits. On a server that does not read `.htaccess` a closed directory is closed by nothing, and the name of a backup or of a run has 32 random bits
 * only; the names of the storage and of the data directory are what keep the archive, the profile (with a database password the person typed) and
 * the state of a run (with a passphrase) from being guessed. The work files of a run are in the storage already (`.work-<backup>/`).
 */
final class BackupWorkspace
{
    public const string NAME = '.threadsafe-backup';

    /** The id of the one profile of the tool. */
    public const string PROFILE = 'site';

    private const string STORAGE_PATTERN = '/^storage-[0-9a-f]{32}$/D';

    private const string DATA_PATTERN = '/^data-[0-9a-f]{32}$/D';

    private function __construct(private readonly string $directory, private readonly bool $custom = false)
    {
    }

    /**
     * @param string $baseDirectory the directory the tool is in
     */
    public static function in(string $baseDirectory): self
    {
        return new self(rtrim($baseDirectory, '/\\') . '/' . self::NAME);
    }

    /**
     * A workspace in a directory of its own, outside the directory of the tool (docs/PHASE13.md 9.2): a plugin that holds the tool keeps the state
     * and the archives elsewhere, because an update of the plugin replaces its whole directory. Check the path with {@see customProblem()} first.
     */
    public static function at(string $directory): self
    {
        return new self(rtrim($directory, '/\\'), true);
    }

    /** Whether the workspace was put where a person or a plugin chose (and not next to the tool). */
    public function isCustom(): bool
    {
        return $this->custom;
    }

    /**
     * Why a directory cannot be the workspace of a tool that lies in `$toolDirectory`, or null if it can. A path is not trusted because a plugin
     * wrote it: no stream wrapper (`phar://`, `php://`), an absolute path without `..`, and neither inside the directory of the tool (an update of a
     * plugin would delete the pairing and the backups with it) nor around it.
     */
    public static function customProblem(string $path, string $toolDirectory): ?string
    {
        if ($path === '' || str_contains($path, "\0")) {
            return 'the path is empty or holds a character that no path holds';
        }
        if (str_contains($path, '://')) {
            return 'a stream wrapper (phar://, php:// ...) is not a directory';
        }
        if (preg_match('~^(/|[A-Za-z]:[\\\\/]|\\\\\\\\)~', $path) !== 1) {
            return 'the path is not absolute';
        }
        if (preg_match('~(^|[\\\\/])\.\.([\\\\/]|$)~', $path) === 1) {
            return 'the path holds ".."';
        }
        $directory = self::resolved($path);
        $tool = self::resolved($toolDirectory);
        if ($directory === $tool || str_starts_with($directory . '/', $tool . '/')) {
            return 'it lies inside the directory of the tool (' . $tool . '): an update of a plugin replaces that directory and would delete the pairing and the backups';
        }
        if (str_starts_with($tool . '/', $directory . '/')) {
            return 'the directory of the tool (' . $tool . ') lies inside it';
        }

        return null;
    }

    /**
     * An absolute path with forward slashes and no trailing one, and with the links of its existing part resolved (the rest is not there yet).
     */
    public static function resolved(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $rest = '';
        $existing = $path;
        while ($existing !== '' && $existing !== '/' && !file_exists($existing)) {
            $rest = '/' . basename($existing) . $rest;
            $parent = dirname($existing);
            if ($parent === $existing || $parent === '.') {
                break;
            }
            $existing = $parent;
        }
        $real = $existing === '' ? false : realpath($existing);

        return ($real === false ? $existing : rtrim(str_replace('\\', '/', $real), '/')) . $rest;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * The data directory (it is made, closed to the web, when asked for).
     *
     * @throws RuntimeException it cannot be made
     */
    public function data(): string
    {
        return $this->prepared($this->existingData() ?? $this->directory . '/data-' . bin2hex(random_bytes(16)));
    }

    /** The data directory if it has been made, else null (nothing is made). */
    public function existingData(): ?string
    {
        return $this->existing(self::DATA_PATTERN);
    }

    /**
     * The storage: the existing `storage-<random>/`, or a new one. There is one per tool.
     *
     * @throws RuntimeException it cannot be made
     */
    public function storage(): string
    {
        return $this->prepared($this->existingStorage() ?? $this->directory . '/storage-' . bin2hex(random_bytes(16)));
    }

    /** The storage if it has been made, else null (nothing is made). */
    public function existingStorage(): ?string
    {
        return $this->existing(self::STORAGE_PATTERN);
    }

    private function existing(string $pattern): ?string
    {
        $names = is_dir($this->directory) ? (scandir($this->directory) ?: []) : [];
        foreach ($names as $name) {
            if (preg_match($pattern, $name) === 1 && is_dir($this->directory . '/' . $name)) {
                return $this->directory . '/' . $name;
            }
        }

        return null;
    }

    public function profiles(): ProfileStore
    {
        return new ProfileStore($this->data() . '/profiles');
    }

    public function runs(): RunStore
    {
        return new RunStore($this->data() . '/runs');
    }

    private function prepared(string $directory): string
    {
        StateDirectory::prepare($this->directory);
        StateDirectory::prepare($directory);

        return $directory;
    }
}
