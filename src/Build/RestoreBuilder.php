<?php

declare(strict_types=1);

namespace ThreadSafe\Build;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ThreadSafe\Core\Version;

/**
 * Builds the universal restore tool, `dist/threadsafe-restore.php` (docs/PHASE3.md 3.1): the stub ({@see ToolStub}) followed by the
 * deflated payload, which is the bundle of all the code ({@see Bundler}) and the plugins' data files. It is the same file for every
 * backup and every website type; it is built once and kept with the backups.
 *
 * What goes in: all of `src/` except `src/Build` (the build itself), `src/Admin` (the web interface, which a restore has no use
 * for and which would triple the size) and `src/Auth` (the second factor of its login), the PHP of every plugin, and each plugin's `plugin.json` and
 * `resources/`, which the code reads from disk at run time. Nothing else: no tests, no documentation, no configuration.
 */
final class RestoreBuilder
{
    /** What only the restore tool needs: its wizard and its entry. The backup tool leaves these out. */
    public const array ONLY_RESTORE = ['src/Web/Wizard.php', 'src/Web/Entry.php', 'src/Cli/RestoreCommand.php'];

    /** What only the backup tool needs ({@see BackupBuilder}). The restore tool leaves these out. */
    public const array ONLY_BACKUP = ['src/Web/BackupEntry.php', 'src/Web/BackupWizard.php', 'src/Web/BackupWorkspace.php', 'src/Web/BackupTarget.php', 'src/Cli/ToolBackupCommand.php'];

    private readonly string $root;

    /**
     * @param string|null $version the version the tool says it is (the header of the file); default: the one of the running code. A release
     *                             builds the tool from its staged tree, whose Version.php is stamped, with that version.
     * @param list<string> $leaveOut files (relative to the root) that are not part of the program: those of the other tool
     */
    public function __construct(string $root, private readonly ?string $version = null, private readonly array $leaveOut = self::ONLY_BACKUP)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    /**
     * @return list<string> absolute paths of the PHP files that make up the program, sorted
     */
    public function sourceFiles(): array
    {
        $files = [];
        foreach ([$this->root . '/src', $this->root . '/plugins'] as $directory) {
            foreach ($this->walk($directory) as $path) {
                $relative = substr($path, strlen($this->root) + 1);
                if (str_ends_with($path, '.php') && !str_starts_with($relative, 'src/Build/') && !str_starts_with($relative, 'src/Admin/') && !str_starts_with($relative, 'src/Auth/') && !str_contains($relative, '/resources/') && !in_array($relative, $this->leaveOut, true)) {
                    $files[] = $path;
                }
            }
        }
        sort($files);

        return $files;
    }

    /**
     * The data files the code reads at run time, with the path they have below the program directory.
     *
     * @return array<string, string> path (`plugins/prestashop9/plugin.json`) => content
     */
    public function resources(): array
    {
        $resources = [];
        foreach ($this->walk($this->root . '/plugins') as $path) {
            $relative = substr($path, strlen($this->root) + 1);
            if (str_ends_with($relative, '/plugin.json') || str_contains($relative, '/resources/')) {
                $resources[$relative] = (string) file_get_contents($path);
            }
        }
        ksort($resources);

        return $resources;
    }

    /** All the code as one PHP file (what `composer test:bundle` runs the unit tests against). */
    public function bundle(): string
    {
        return (new Bundler())->bundle($this->sourceFiles(), $this->root, 'ThreadSafe ' . Version::CURRENT);
    }

    /**
     * Writes the restore tool.
     *
     * @return array{bytes: int, bundleBytes: int, files: int, resources: int}
     *
     * @throws RuntimeException the file cannot be written, or the sources cannot be bundled
     */
    public function build(string $output): array
    {
        return $this->buildWith(ToolStub::restore($this->version ?? Version::CURRENT), $output);
    }

    /**
     * Writes a tool that has this program in it and the given stub: the restore tool, or the backup tool ({@see BackupBuilder}), which have the same
     * code and the same plugin files and differ in their stub and in the entry that the stub calls.
     *
     * @return array{bytes: int, bundleBytes: int, files: int, resources: int}
     *
     * @throws RuntimeException the file cannot be written, or the sources cannot be bundled
     */
    public function buildWith(string $stub, string $output): array
    {
        $bundle = $this->bundle();
        $resources = $this->resources();

        $payload = self::entry('bundle.php', $bundle);
        foreach ($resources as $path => $content) {
            $payload .= self::entry($path, $content);
        }
        $deflated = gzdeflate($payload, 9);
        if ($deflated === false) {
            throw new RuntimeException('Cannot compress the program.');
        }

        $directory = dirname($output);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create the directory \"$directory\".");
        }
        $file = $stub . $deflated;
        if (@file_put_contents($output, $file) === false) {
            throw new RuntimeException("Cannot write \"$output\".");
        }
        @chmod($output, 0644);

        return ['bytes' => strlen($file), 'bundleBytes' => strlen($bundle), 'files' => count($this->sourceFiles()), 'resources' => count($resources)];
    }

    private static function entry(string $name, string $content): string
    {
        if (str_contains($name, "\t") || str_contains($name, "\n") || str_contains($name, '..')) {
            throw new RuntimeException("The file name \"$name\" cannot be put into the tool.");
        }

        return $name . "\t" . strlen($content) . "\n" . $content;
    }

    /**
     * @return list<string> files below $directory, with forward slashes
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
