<?php

declare(strict_types=1);

namespace ThreadSafe\Build;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ThreadSafe\Core\Version;

/**
 * Builds the web installer `install.php` (docs/PHASE7.md 3.3): {@see InstallStub}, the installer's code (the bundle of the program
 * without the web interface and the build tools, deflated), and behind them the release package as a ZIP archive with shifted offsets
 * ({@see ZipShift}), so that the file is the installer, the package, and a ZIP archive that `unzip` opens.
 *
 * It is built from the tree the package was made from (`tools/build-release.php`), so that the code in the installer and the code in
 * the package are the same.
 */
final class InstallBuilder
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    /**
     * The PHP files of the installer's code: all of `src/` except the web interface and the build tools, which an installer has no use
     * for; of the build tools only the manifest, which it reads.
     *
     * @return list<string> absolute paths, sorted
     */
    public function sourceFiles(): array
    {
        $files = [];
        foreach ($this->walk($this->root . '/src') as $path) {
            $relative = substr($path, strlen($this->root) + 1);
            $excluded = str_starts_with($relative, 'src/Admin/') || (str_starts_with($relative, 'src/Build/') && $relative !== 'src/Build/ReleaseManifest.php');
            if (str_ends_with($path, '.php') && !$excluded) {
                $files[] = $path;
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /** The installer's code as one PHP file. */
    public function bundle(): string
    {
        return (new Bundler())->bundle($this->sourceFiles(), $this->root, 'ThreadSafe installer ' . Version::CURRENT);
    }

    /**
     * Writes `install.php`.
     *
     * @param string $package path of the release package (a ZIP archive)
     *
     * @return array{bytes: int, codeBytes: int, files: int}
     *
     * @throws RuntimeException
     */
    public function build(string $output, string $package, string $version): array
    {
        $code = $this->bundle();
        $deflated = gzdeflate($code, 9);
        $zip = @file_get_contents($package);
        if ($deflated === false || $zip === false) {
            throw new RuntimeException('Cannot read the package or compress the installer.');
        }

        $prefix = InstallStub::source($version) . strlen($deflated) . "\n" . $deflated;
        $file = $prefix . ZipShift::shift($zip, strlen($prefix));

        $directory = dirname($output);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create the directory \"$directory\".");
        }
        if (@file_put_contents($output, $file) === false) {
            throw new RuntimeException("Cannot write \"$output\".");
        }
        @chmod($output, 0644);

        return ['bytes' => strlen($file), 'codeBytes' => strlen($code), 'files' => count($this->sourceFiles())];
    }

    /**
     * @return list<string>
     */
    private function walk(string $directory): array
    {
        $files = [];
        if (!is_dir($directory)) {
            return $files;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        return $files;
    }
}
