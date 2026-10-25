<?php

declare(strict_types=1);

namespace ThreadSafe\Build;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ThreadSafe\Archive\ZipException;
use ThreadSafe\Archive\ZipWriter;
use ThreadSafe\Core\Version;

/**
 * Builds the release package `threadsafe-X.Y.Z.zip` (docs/PHASE7.md 3.1): the program, the autoloader of `vendor/`, the user
 * documentation and the manifest {@see ReleaseManifest}, in one top directory `threadsafe-X.Y.Z/`.
 *
 * The package is **reproducible**: the files come in sorted order, every file has the time of the build (SOURCE_DATE_EPOCH) and
 * the mode 0644 (0755 below `bin/`), and the archive is written by our own writer, so two builds of one input are the same bytes.
 *
 * The version is stamped into `src/Core/Version.php` of a copy ({@see stage()}); {@see write()} refuses a tree whose version differs
 * from the one it is asked to build, so an unstamped package cannot be made by mistake. `vendor/` is made by
 * `composer install --no-dev --optimize-autoloader` in that copy (`tools/build-release.php`), which is why the package needs no Composer.
 */
final class ReleaseBuilder
{
    /** Directories that go in whole. */
    private const array DIRECTORIES = ['bin', 'plugins', 'public', 'src'];

    /** Files of the root that go in if they exist. */
    private const array FILES = ['composer.json', 'LICENSE', 'README.md', 'CHANGELOG.md', 'INSTALL.md'];

    /** The documents of `docs/` that a user reads. */
    private const array DOCS = ['BACKUP.md', 'ENCRYPTION.md', 'JOOMLA.md', 'MIGRATE.md', 'RESTORE.md', 'SCHEDULE.md', 'WEB.md'];

    private const string VERSION_FILE = 'src/Core/Version.php';

    private readonly string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    /**
     * The files of the program and the documents, without `vendor/`.
     *
     * @return list<string> paths relative to the root, sorted
     */
    public function programFiles(): array
    {
        $files = [];
        foreach (self::DIRECTORIES as $directory) {
            foreach ($this->walk($directory) as $path) {
                $files[] = $path;
            }
        }
        foreach (self::FILES as $file) {
            if (is_file($this->root . '/' . $file)) {
                $files[] = $file;
            }
        }
        foreach (self::DOCS as $document) {
            if (is_file($this->root . '/docs/' . $document)) {
                $files[] = 'docs/' . $document;
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Everything that goes into the package: the program files and, if there is a `vendor/`, its files.
     *
     * @return list<string>
     */
    public function packageFiles(): array
    {
        $files = array_merge($this->programFiles(), $this->walk('vendor'));
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Copies what a build needs (the program files, `composer.lock`) to $directory and stamps the version into the copy. $directory
     * must be empty or new.
     *
     * @throws RuntimeException
     */
    public function stage(string $directory, string $version): void
    {
        if (!Version::isValid($version)) {
            throw new RuntimeException("\"$version\" is not a valid version.");
        }
        if (is_dir($directory) && (new FilesystemIterator($directory))->valid()) {
            throw new RuntimeException("\"$directory\" is not empty.");
        }
        $files = $this->programFiles();
        if (is_file($this->root . '/composer.lock')) {
            $files[] = 'composer.lock';
        }
        foreach ($files as $relative) {
            $target = rtrim(str_replace('\\', '/', $directory), '/') . '/' . $relative;
            if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0777, true) && !is_dir(dirname($target))) {
                throw new RuntimeException('Cannot create the directory "' . dirname($target) . '".');
            }
            $content = (string) file_get_contents($this->root . '/' . $relative);
            if ($relative === self::VERSION_FILE) {
                $content = self::stampVersion($content, $version);
            }
            if (@file_put_contents($target, $content) === false) {
                throw new RuntimeException("Cannot write \"$target\".");
            }
        }
    }

    /**
     * The source of `Version.php` with the version of the release in it.
     *
     * @throws RuntimeException the file has not exactly one line for the version
     */
    public static function stampVersion(string $source, string $version): string
    {
        if (!Version::isValid($version)) {
            throw new RuntimeException("\"$version\" is not a valid version.");
        }
        $count = 0;
        $stamped = preg_replace("/(public const string CURRENT = ')[^']*(';)/", '${1}' . $version . '${2}', $source, -1, $count);
        if ($count !== 1 || $stamped === null) {
            throw new RuntimeException('Version.php has not exactly one line "public const string CURRENT".');
        }

        return $stamped;
    }

    /** The version a tree says it has, or null if it cannot be read. */
    public function stampedVersion(): ?string
    {
        $source = @file_get_contents($this->root . '/' . self::VERSION_FILE);

        return is_string($source) && preg_match("/public const string CURRENT = '([^']*)';/", $source, $m) === 1 ? $m[1] : null;
    }

    /**
     * Writes the package.
     *
     * @param int $epoch the time every file gets (SOURCE_DATE_EPOCH)
     *
     * @throws RuntimeException the tree is not stamped with $version, or the file cannot be written
     */
    public function write(string $zipPath, string $version, int $epoch): ReleaseManifest
    {
        if ($this->stampedVersion() !== $version) {
            throw new RuntimeException('The tree says version ' . ($this->stampedVersion() ?? '(none)') . ', not ' . $version . '; stamp it first (stage()).');
        }
        $files = [];
        $contents = [];
        foreach ($this->packageFiles() as $relative) {
            $content = @file_get_contents($this->root . '/' . $relative);
            if ($content === false) {
                throw new RuntimeException("Cannot read \"$relative\".");
            }
            $contents[$relative] = $content;
            $files[$relative] = ['size' => strlen($content), 'sha256' => hash('sha256', $content)];
        }
        $requirements = ReleaseManifest::requirements();
        $manifest = new ReleaseManifest($version, $epoch, $requirements['php'], $requirements['extensions'], Version::DATA_FORMAT, $files);

        $directory = dirname($zipPath);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create the directory \"$directory\".");
        }
        @unlink($zipPath);
        $top = self::topDirectory($version);
        try {
            $zip = ZipWriter::create($zipPath, 9);
            // the manifest comes first: whoever reads the package finds out what it is before anything else is unpacked
            $zip->addString($top . ReleaseManifest::FILE, $manifest->toJson(), $epoch, 0100644);
            foreach ($contents as $relative => $content) {
                $zip->addString($top . $relative, $content, $epoch, str_starts_with($relative, 'bin/') ? 0100755 : 0100644);
            }
            $zip->close();
        } catch (ZipException $e) {
            throw new RuntimeException('Cannot write the package: ' . $e->getMessage(), 0, $e);
        }

        return $manifest;
    }

    /** `threadsafe-0.1.0/` */
    public static function topDirectory(string $version): string
    {
        return ReleaseManifest::topDirectory($version);
    }

    /** `threadsafe-0.1.0.zip` */
    public static function packageName(string $version): string
    {
        return 'threadsafe-' . $version . '.zip';
    }

    /**
     * The file of checksums, in the format of `sha256sum -c`.
     *
     * @param array<string, string> $files file name => path of the file
     */
    public static function checksums(array $files): string
    {
        ksort($files, SORT_STRING);
        $lines = '';
        foreach ($files as $name => $path) {
            $hash = hash_file('sha256', $path);
            if ($hash === false) {
                throw new RuntimeException("Cannot read \"$path\".");
            }
            $lines .= $hash . '  ' . $name . "\n";
        }

        return $lines;
    }

    /**
     * @return list<string> files below $directory (relative to the root), forward slashes, without the leftovers of editors and git
     */
    private function walk(string $directory): array
    {
        $base = $this->root . '/' . $directory;
        $files = [];
        if (!is_dir($base)) {
            return $files;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile() || str_starts_with($file->getFilename(), '.git') || in_array($file->getFilename(), ['.DS_Store', 'Thumbs.db'], true)) {
                continue;
            }
            $files[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($this->root) + 1);
        }
        sort($files, SORT_STRING);

        return $files;
    }
}
