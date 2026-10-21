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
     *
     * @return list<string> what could not be deleted, as messages for the person
     */
    public static function run(array $files, string $stateDirectory): array
    {
        $problems = [];
        foreach ($files as $file) {
            if (is_file($file) && !@unlink($file)) {
                $problems[] = "Delete the file \"$file\" by hand.";
            }
        }
        if (is_dir($stateDirectory) && !self::removeTree($stateDirectory)) {
            $problems[] = "Delete the directory \"$stateDirectory\" by hand.";
        }

        return $problems;
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
