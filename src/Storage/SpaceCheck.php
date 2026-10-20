<?php

declare(strict_types=1);

namespace ThreadSafe\Storage;

use Closure;

/**
 * Checks before a backup that the storage directory has room for it. The estimate is deliberately generous: a backup that
 * dies of a full disk wastes the time of the whole run.
 */
final class SpaceCheck
{
    /** @var Closure(string): (int|float|false) free bytes of the file system a directory is on */
    private readonly Closure $freeSpace;

    /** The last backup of the profile, times this, is the room a new one is expected to need. */
    public const float GROWTH = 1.2;

    /** Without a previous backup: files as they are (they compress), the database at this multiple of its size. */
    public const float DATABASE_FACTOR = 1.5;

    /**
     * The space a backup needs in the storage directory. Besides the archive this counts the dump files, which wait in the
     * work directory (inside the storage) until they are packed.
     *
     * @param int|null $lastBackupBytes the size of the profile's last backup, if there is one
     * @param int      $fileBytes       size of the files of the site as scanned (or estimated)
     * @param int      $databaseBytes   size of the tables as the server reports them (data and indexes)
     */
    public static function required(?int $lastBackupBytes, int $fileBytes, int $databaseBytes): int
    {
        $archive = $lastBackupBytes !== null
            ? (int) ceil($lastBackupBytes * self::GROWTH)
            : $fileBytes + (int) ceil($databaseBytes * self::DATABASE_FACTOR);

        return $archive + $databaseBytes;
    }

    /**
     * @param (Closure(string): (int|float|false))|null $freeSpace the free bytes of the file system a directory is on, false if unknown
     *                                                      (default: `disk_free_space`; tests pass their own)
     */
    public function __construct(?Closure $freeSpace = null)
    {
        $this->freeSpace = $freeSpace ?? static fn (string $directory): float|false => @disk_free_space($directory);
    }

    /**
     * @param bool $force go on although the space is not enough (with a warning)
     */
    public function check(string $directory, int $required, bool $force = false): SpaceResult
    {
        $existing = $directory;
        while (!file_exists($existing) && dirname($existing) !== $existing) {
            $existing = dirname($existing);
        }
        $free = ($this->freeSpace)($existing);
        if ($free === false) {
            return new SpaceResult(true, null, $required, 'The free space of the storage directory cannot be determined; the backup goes on without the check.');
        }
        $free = (int) $free;
        if ($free >= $required) {
            return new SpaceResult(true, $free, $required);
        }
        if ($force) {
            return new SpaceResult(true, $free, $required, sprintf('Only %s free where about %s are expected to be needed; going on because of --force.', self::format($free), self::format($required)));
        }

        return new SpaceResult(false, $free, $required);
    }

    public static function format(int $bytes): string
    {
        return match (true) {
            $bytes >= 1073741824 => sprintf('%.1f GB', $bytes / 1073741824),
            $bytes >= 1048576 => sprintf('%.0f MB', $bytes / 1048576),
            default => sprintf('%.0f KB', $bytes / 1024),
        };
    }
}
