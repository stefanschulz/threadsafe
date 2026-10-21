<?php

declare(strict_types=1);

namespace ThreadSafe\Storage;

use DateTimeImmutable;
use ThreadSafe\Backup\BackupName;
use ThreadSafe\Backup\ManifestException;
use ThreadSafe\Backup\Sidecar;

/**
 * The directory where the backups of one profile are kept, on this server.
 *
 * - The directory is mode 0700 and every file written here 0600; nobody but the owner reads a backup.
 * - **Protection files** keep a web server from serving the directory if it lies below the document root: `.htaccess`
 *   (Apache 2.4 and 2.2), `index.php` (answers 403) and `web.config` (IIS). They are written by {@see prepare()} and never
 *   overwritten if they exist. They are a second line: the storage should be outside the web root.
 * - A backup is a set of files named after a {@see BackupName}; while a backup is made, its parts exist as `<name>.part` and
 *   `.cd` files and work files live in `.work-<base>/`. {@see removeBackup()} deletes all of it, which is what a failed run
 *   does.
 */
final class LocalStorage
{
    public function __construct(private readonly string $directory)
    {
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Creates the directory if needed and the protection files.
     *
     * @throws StorageException
     */
    public function prepare(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new StorageException("Cannot create the storage directory \"{$this->directory}\".");
        }
        if (!is_writable($this->directory)) {
            throw new StorageException("The storage directory \"{$this->directory}\" is not writable.");
        }
        foreach (self::protectionFiles() as $name => $content) {
            $path = $this->directory . '/' . $name;
            if (!file_exists($path)) {
                $this->write($path, $content);
            }
        }
    }

    /** Whether all protection files are in place. */
    public function isProtected(): bool
    {
        foreach (array_keys(self::protectionFiles()) as $name) {
            if (!is_file($this->directory . '/' . $name)) {
                return false;
            }
        }

        return true;
    }

    public function newName(string $profileId, ?DateTimeImmutable $now = null): BackupName
    {
        return BackupName::create($profileId, $now ?? new DateTimeImmutable('now'));
    }

    public function partPath(BackupName $name, int $index, bool $split): string
    {
        return $this->directory . '/' . $name->partFile($index, $split);
    }

    public function sidecarPath(BackupName $name): string
    {
        return $this->directory . '/' . $name->sidecarFile();
    }

    /** The work directory of a backup in progress (the dump files wait here until they are packed). Created 0700. */
    public function workDirectory(BackupName $name): string
    {
        $path = $this->directory . '/.work-' . $name->base();
        if (!is_dir($path) && !@mkdir($path, 0700) && !is_dir($path)) {
            throw new StorageException("Cannot create the work directory \"$path\".");
        }

        return $path;
    }

    /** Deletes the work directory of a backup (dump files and file list). */
    public function removeWorkDirectory(BackupName $name): void
    {
        $this->removeTree($this->directory . '/.work-' . $name->base());
    }

    /**
     * Writes the sidecar file atomically.
     *
     * @throws StorageException
     */
    public function writeSidecar(BackupName $name, Sidecar $sidecar): void
    {
        $this->write($this->sidecarPath($name), $sidecar->toJson());
    }

    /**
     * @throws StorageException no such sidecar file, or it is damaged
     */
    public function readSidecar(BackupName $name): Sidecar
    {
        $path = $this->sidecarPath($name);
        $content = is_file($path) ? @file_get_contents($path) : false;
        if ($content === false) {
            throw new StorageException("There is no sidecar file for backup \"{$name->base()}\".");
        }
        try {
            return Sidecar::fromJson($content);
        } catch (ManifestException $e) {
            throw new StorageException("The sidecar file of backup \"{$name->base()}\" is unusable: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * The backups that leave a trace in the directory: a sidecar file or an archive part, newest first.
     *
     * @return list<BackupName>
     */
    public function names(?string $profileId = null): array
    {
        $found = [];
        foreach (@scandir($this->directory) ?: [] as $file) {
            $parsed = BackupName::fromFile($file);
            if ($parsed !== null && ($profileId === null || $parsed['name']->profile === $profileId)) {
                $found[$parsed['name']->base()] = $parsed['name'];
            }
        }
        $names = array_values($found);
        usort($names, static fn (BackupName $a, BackupName $b): int => [$b->time, $b->base()] <=> [$a->time, $a->base()]);

        return $names;
    }

    /**
     * The files of a backup that exist, parts in order and the sidecar file last; unfinished parts (`.part`, `.cd`) are
     * included.
     *
     * @return list<string> absolute paths
     */
    public function files(BackupName $name): array
    {
        $parts = [];
        $others = [];
        foreach (@scandir($this->directory) ?: [] as $file) {
            if (!str_starts_with($file, $name->base())) {
                continue;
            }
            $rest = substr($file, strlen($name->base()));
            if (preg_match('/^(-p\d{2,})?\.(zip|zip\.part|zip\.cd)$/', $rest) === 1) {
                $parts[] = $this->directory . '/' . $file;
            } elseif ($rest === '.json') {
                $others[] = $this->directory . '/' . $file;
            }
        }
        sort($parts);

        return [...$parts, ...$others];
    }

    /**
     * Deletes everything of a backup: parts, unfinished parts, sidecar file and work directory.
     */
    public function removeBackup(BackupName $name): void
    {
        foreach ($this->files($name) as $file) {
            @unlink($file);
        }
        $this->removeWorkDirectory($name);
    }

    /**
     * Unfinished backups: names with a `.part` file or a work directory but no sidecar file. A run that is still going also
     * looks like this - the caller decides with the run lock whether it is dead.
     *
     * @return list<BackupName>
     */
    public function unfinished(): array
    {
        $found = [];
        foreach (@scandir($this->directory) ?: [] as $file) {
            $base = null;
            if (str_starts_with($file, '.work-')) {
                $base = substr($file, 6);
            } elseif (preg_match('/^(.+?)(?:-p\d{2,})?\.zip\.(?:part|cd)$/', $file, $m) === 1) {
                $base = $m[1];
            }
            $name = $base === null ? null : BackupName::parse($base);
            if ($name !== null && !is_file($this->sidecarPath($name))) {
                $found[$name->base()] = $name;
            }
        }

        return array_values($found);
    }

    /**
     * @throws StorageException
     */
    private function write(string $path, string $content): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new StorageException("Cannot write \"$temporary\".");
        }
        @chmod($temporary, 0600);
        $written = fwrite($handle, $content);
        fclose($handle);
        if ($written !== strlen($content) || !@rename($temporary, $path)) {
            @unlink($temporary);

            throw new StorageException("Cannot write \"$path\" (disk full or no permission).");
        }
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (@scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    /**
     * The files that keep a web server from serving a backup directory, with their content (a restore recognises them next to an
     * uploaded backup and does not count them as leftovers).
     *
     * @return array<string, string> file name => content
     */
    public static function protectionFiles(): array
    {
        return [
            '.htaccess' => "# ThreadSafe: backups are never served to the web.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n",
            'index.php' => "<?php\n// ThreadSafe backups are stored here. Nothing to see.\nhttp_response_code(403);\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n    <system.webServer>\n        <security>\n            <authorization>\n                <remove users=\"*\" roles=\"\" verbs=\"\" />\n                <add accessType=\"Deny\" users=\"*\" />\n            </authorization>\n        </security>\n    </system.webServer>\n</configuration>\n",
        ];
    }
}
