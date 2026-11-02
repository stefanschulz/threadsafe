<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

/**
 * Takes the restore tool off the server when the restore is done: the tool itself, the key file and the state directory (and, if
 * the person asks, the archive parts). A tool left behind is an invitation (docs/PHASE3.md 3.2), so what cannot be deleted is
 * named in the result.
 */
final class Cleanup
{
    /**
     * @param list<string> $files files to delete (the script, the key file, archive parts)
     * @param list<string> $keep  paths that lie in the state directory and stay (the archive of the backup tool): the rest of the state directory goes, the
     *                            directory itself stays
     *
     * @return list<string> what could not be deleted, as messages for the person
     */
    public static function run(array $files, string $stateDirectory, array $keep = []): array
    {
        $problems = [];
        foreach ($files as $file) {
            if (is_file($file) && !@unlink($file)) {
                $problems[] = "Delete the file \"$file\" by hand.";
            }
        }
        $kept = is_dir($stateDirectory) ? self::keptBelow($stateDirectory, $keep) : [];
        if (is_dir($stateDirectory) && $kept === [] && !self::removeTree($stateDirectory)) {
            $problems[] = "Delete the directory \"$stateDirectory\" by hand.";
        }
        foreach ($kept === [] ? [] : (scandir($stateDirectory) ?: []) as $name) {
            if ($name !== '.' && $name !== '..' && !in_array($name, $kept, true) && !self::removeTree($stateDirectory . '/' . $name)) {
                $problems[] = "Delete \"$stateDirectory/$name\" by hand.";
            }
        }

        return $problems;
    }

    /**
     * The names of the entries of a directory that hold one of the paths.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private static function keptBelow(string $directory, array $paths): array
    {
        $base = rtrim(str_replace('\\', '/', (string) realpath($directory)), '/') . '/';
        $names = [];
        foreach ($paths as $path) {
            $real = realpath($path);
            $real = $real === false ? '' : str_replace('\\', '/', $real) . '/';
            if ($real !== '' && str_starts_with($real, $base)) {
                $names[] = explode('/', substr($real, strlen($base)), 2)[0];
            }
        }

        return $names;
    }

    private static function removeTree(string $path): bool
    {
        if (is_link($path) || is_file($path)) {
            return @unlink($path);
        }
        $ok = true;
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $ok = self::removeTree($path . '/' . $name) && $ok;
            }
        }

        return @rmdir($path) && $ok;
    }
}
