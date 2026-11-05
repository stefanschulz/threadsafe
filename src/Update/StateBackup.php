<?php

declare(strict_types=1);

namespace ThreadSafe\Update;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The copy of the small state files of the data directory that an update takes before it switches (docs/PHASE7.md 3.5): the profiles,
 * the configuration, the schedule, the retention state, the administrator, the second factor, the cron secret and the data format. Not the runs, the
 * sessions, the audit log and, above all, not the backups. A failed update puts this copy back, so that a migration that stopped half way
 * leaves no trace.
 */
final class StateBackup
{
    public const string DIRECTORY = 'update-backup';

    private const array FILES = ['config.json', 'admin.json', 'two-factor.json', 'cron-secret', DataFormat::FILE];

    private const array DIRECTORIES = ['profiles', 'schedule', 'retention'];

    /**
     * Makes the copy in `<data>/update-backup/<label>/`.
     *
     * @return string the directory of the copy
     *
     * @throws UpdateException
     */
    public static function make(string $data, string $label): string
    {
        $data = rtrim($data, '/\\');
        $target = $data . '/' . self::DIRECTORY . '/' . $label;
        if (!is_dir($target) && !@mkdir($target, 0700, true) && !is_dir($target)) {
            throw new UpdateException("Cannot create the directory \"$target\" for the copy of the state files.");
        }
        @chmod(dirname($target), 0700);
        foreach (self::FILES as $name) {
            if (is_file($data . '/' . $name)) {
                self::copyFile($data . '/' . $name, $target . '/' . $name);
            }
        }
        foreach (self::DIRECTORIES as $name) {
            foreach (self::files($data . '/' . $name) as $relative => $path) {
                self::copyFile($path, $target . '/' . $name . '/' . $relative);
            }
        }

        return $target;
    }

    /**
     * Puts a copy back: what the state files are now is removed first, so that files the failed update made are gone.
     *
     * @throws UpdateException
     */
    public static function restore(string $data, string $copy): void
    {
        $data = rtrim($data, '/\\');
        if (!is_dir($copy)) {
            throw new UpdateException("The copy of the state files \"$copy\" is not there.");
        }
        foreach (self::FILES as $name) {
            @unlink($data . '/' . $name);
            if (is_file($copy . '/' . $name)) {
                self::copyFile($copy . '/' . $name, $data . '/' . $name);
            }
        }
        foreach (self::DIRECTORIES as $name) {
            foreach (self::files($data . '/' . $name) as $path) {
                @unlink($path);
            }
            foreach (self::files($copy . '/' . $name) as $relative => $path) {
                self::copyFile($path, $data . '/' . $name . '/' . $relative);
            }
        }
    }

    /**
     * @return array<string, string> path relative to $directory => absolute path, of the files below it
     */
    private static function files(string $directory): array
    {
        $files = [];
        if (!is_dir($directory)) {
            return $files;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $path = str_replace('\\', '/', $file->getPathname());
                $files[substr($path, strlen(rtrim(str_replace('\\', '/', $directory), '/')) + 1)] = $path;
            }
        }

        return $files;
    }

    private static function copyFile(string $from, string $to): void
    {
        if (!is_dir(dirname($to)) && !@mkdir(dirname($to), 0700, true) && !is_dir(dirname($to))) {
            throw new UpdateException('Cannot create the directory "' . dirname($to) . '".');
        }
        if (!@copy($from, $to)) {
            throw new UpdateException("Cannot copy \"$from\" to \"$to\".");
        }
        @chmod($to, fileperms($from) & 0777);
    }

    private function __construct()
    {
    }
}
