<?php

declare(strict_types=1);

namespace ThreadSafe\Install;

use ThreadSafe\Archive\Entry;
use ThreadSafe\Archive\ZipException;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Build\ReleaseManifest;
use ThreadSafe\Engine\Budget;

/**
 * Unpacks a release package into a directory, entry by entry, in ticks (docs/PHASE7.md 3.3 and 3.5): the installer and the update do
 * the same thing here, so they share it. Every file is checked against its size and SHA-256 in `release.json` while it is written; a file
 * that is not in the manifest, a path outside the top directory, an entry that is no plain file, or a package with more or fewer files
 * than its manifest lists stops it with an {@see InstallException}. The state is plain data (`entry`, `position`, `files`, `bytes`), so
 * the work goes on after a cut-off; a file that was cut off in the middle is written again.
 */
final class Unpacker
{
    /**
     * The state a new unpacking starts with.
     *
     * @return array{entry: int, position: int, files: int, bytes: int, expected: int}
     */
    public static function initialState(InstallPackage $package): array
    {
        return ['entry' => 0, 'position' => 0, 'files' => 0, 'bytes' => 0, 'expected' => count($package->manifest->files) + 1];
    }

    /**
     * Works for at most the budget (but always unpacks one entry).
     *
     * @param array<string, mixed> $state the keys of {@see initialState()}, and others, which are kept
     *
     * @return array{array<string, mixed>, bool} the new state, and whether the whole package is unpacked and complete
     *
     * @throws InstallException
     */
    public function unpack(array $state, string $file, string $target, Budget $budget): array
    {
        $package = InstallPackage::open($file);
        $manifest = $package->manifest;
        $reader = $package->reader($file);

        $complete = true;
        $first = (int) $state['entry'];
        try {
            foreach ($reader->entries($first, (int) $state['position']) as $index => $entry) {
                if ($budget->exhausted() && $index > $first) {
                    $complete = false;
                    break;
                }
                $state['entry'] = $index + 1;
                $state['position'] = $entry->nextRecord;
                $path = $this->pathOf($entry->name, $package->top);
                if ($entry->isDirectory) {
                    continue;
                }
                if ($entry->mode !== null && ($entry->mode & 0170000) !== 0100000) {
                    throw new InstallException("\"{$entry->name}\" is not a plain file; the package is refused.");
                }
                $expected = $path === ReleaseManifest::FILE ? null : ($manifest->files[$path] ?? throw new InstallException("\"$path\" is in the package but not in its manifest; the package is refused."));
                $this->store($reader, $entry, $target . '/' . $path, $expected);
                $state['files'] = (int) $state['files'] + 1;
                $state['bytes'] = (int) $state['bytes'] + $entry->size;
            }
        } catch (ZipException $e) {
            throw new InstallException('The package in this file is damaged: ' . $e->getMessage(), 0, $e);
        }
        if ($complete && $state['files'] !== $state['expected']) {
            throw new InstallException("The package holds {$state['files']} files, its manifest lists {$state['expected']}; the package is refused.");
        }

        return [$state, $complete];
    }

    /** The path of an entry below the package's top directory; anything else stops the unpacking. */
    private function pathOf(string $name, string $top): string
    {
        if (!str_starts_with($name, $top)) {
            throw new InstallException("\"$name\" is outside the directory \"$top\"; the package is refused.");
        }
        $path = rtrim(substr($name, strlen($top)), '/');
        if ($path !== ReleaseManifest::FILE && !ReleaseManifest::safePath($path)) {
            throw new InstallException("\"$name\" is not a safe path; the package is refused.");
        }

        return $path;
    }

    /**
     * Writes one file of the package and checks it against the manifest.
     *
     * @param array{size: int, sha256: string}|null $expected null for the manifest itself
     */
    private function store(ZipReader $zip, Entry $entry, string $path, ?array $expected): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new InstallException("Cannot create the directory \"$directory\".");
        }
        $temporary = $path . '.tmp';
        $handle = @fopen($temporary, 'wb');
        if ($handle === false) {
            throw new InstallException("Cannot write \"$path\".");
        }
        $hash = hash_init('sha256');
        $written = 0;
        $zip->extract($entry, static function (string $block) use ($handle, $hash, &$written): void {
            fwrite($handle, $block);
            hash_update($hash, $block);
            $written += strlen($block);
        });
        fclose($handle);
        if ($expected !== null && ($written !== $expected['size'] || !hash_equals($expected['sha256'], hash_final($hash)))) {
            @unlink($temporary);

            throw new InstallException("\"{$entry->name}\" does not match its hash in the manifest; the file is damaged (upload the package again) or the package was changed.");
        }
        @chmod($temporary, $entry->mode !== null && ($entry->mode & 0111) !== 0 ? 0755 : 0644);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            throw new InstallException("Cannot write \"$path\".");
        }
    }
}
