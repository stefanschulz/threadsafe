<?php

declare(strict_types=1);

namespace ThreadSafe\Install;

use InvalidArgumentException;
use ThreadSafe\Archive\ZipException;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Build\ReleaseManifest;

/**
 * The release package inside `threadsafe-install.php`: the file itself is the ZIP archive ({@see \ThreadSafe\Build\ZipShift}). The manifest is its
 * first entry; what the installer extracts is checked against it.
 */
final readonly class InstallPackage
{
    private function __construct(public ReleaseManifest $manifest, public string $top)
    {
    }

    /**
     * @throws InstallException the file holds no usable package
     */
    public static function open(string $file): self
    {
        try {
            $zip = ZipReader::open($file);
            if ($zip->count() === 0) {
                throw new InstallException('The package in this file is empty.');
            }
            $first = $zip->entries()->current();
            if (!str_ends_with($first->name, '/' . ReleaseManifest::FILE) || substr_count($first->name, '/') !== 1) {
                throw new InstallException('The package in this file does not start with its manifest (release.json).');
            }
            $manifest = ReleaseManifest::fromJson($zip->contents($first));
            $top = ReleaseManifest::topDirectory($manifest->version);
            if ($first->name !== $top . ReleaseManifest::FILE) {
                throw new InstallException('The manifest names version ' . $manifest->version . ' but the package is in "' . substr($first->name, 0, (int) strpos($first->name, '/')) . '".');
            }
        } catch (ZipException | InvalidArgumentException $e) {
            throw new InstallException('This file is damaged (the package in it cannot be read): ' . $e->getMessage(), 0, $e);
        }

        return new self($manifest, $top);
    }

    public function reader(string $file): ZipReader
    {
        try {
            return ZipReader::open($file);
        } catch (ZipException $e) {
            throw new InstallException('This file is damaged (the package in it cannot be read): ' . $e->getMessage(), 0, $e);
        }
    }
}
