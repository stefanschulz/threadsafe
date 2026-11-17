<?php

declare(strict_types=1);

namespace ThreadSafe\Build;

use RuntimeException;
use ThreadSafe\Archive\ZipException;
use ThreadSafe\Archive\ZipWriter;
use ThreadSafe\Core\Version;

/**
 * Builds the ZIP of the WordPress plugin, `threadsafe-connector-X.Y.Z.zip` (docs/PHASE13.md 9.12): the files of `satellites/wordpress/threadsafe-connector/`
 * with the version stamped into the header of the main file, and the backup tool `threadsafe-backup.php` of the same version next to them. WordPress
 * unpacks the ZIP into `wp-content/plugins/threadsafe-connector/`, so the top directory of the archive has that name. Like the other packages it is
 * written by our own writer with one time for every file, so two builds of one input are the same bytes.
 */
final class ConnectorBuilder
{
    public const string DIRECTORY = 'threadsafe-connector';

    public const string TOOL = 'threadsafe-backup.php';

    private const string MAIN = 'threadsafe-connector.php';

    private readonly string $source;

    /**
     * @param string $root the root of the source tree (the directory that holds `satellites/`)
     */
    public function __construct(string $root)
    {
        $this->source = rtrim(str_replace('\\', '/', $root), '/') . '/satellites/wordpress/' . self::DIRECTORY;
    }

    /** `threadsafe-connector-1.1.0.zip` */
    public static function packageName(string $version): string
    {
        return 'threadsafe-connector-' . $version . '.zip';
    }

    /**
     * The main file with the version of the release in its header.
     *
     * @throws RuntimeException the header has not exactly one line for the version
     */
    public static function stampVersion(string $source, string $version): string
    {
        if (!Version::isValid($version)) {
            throw new RuntimeException("\"$version\" is not a valid version.");
        }
        $count = 0;
        $stamped = preg_replace('/^( \* Version:\h+)\S+/m', '${1}' . $version, $source, -1, $count);
        if ($count !== 1 || $stamped === null) {
            throw new RuntimeException('The main file of the plugin has not exactly one header line "Version:".');
        }

        return $stamped;
    }

    /**
     * @return list<string> the files of the plugin, relative to its directory, sorted
     */
    public function files(): array
    {
        $files = [];
        $base = $this->source;
        $walk = static function (string $directory) use (&$walk, &$files, $base): void {
            foreach (scandir($directory) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $path = $directory . '/' . $name;
                if (is_dir($path) && !is_link($path)) {
                    $walk($path);
                } elseif (is_file($path)) {
                    $files[] = substr($path, strlen($base) + 1);
                }
            }
        };
        if (is_dir($base)) {
            $walk($base);
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param string $tool  the built `threadsafe-backup.php` of the same version
     * @param int    $epoch the time every file gets
     *
     * @return list<string> the names of the entries of the archive
     *
     * @throws RuntimeException something is missing or the file cannot be written
     */
    public function write(string $zipPath, string $tool, string $version, int $epoch): array
    {
        $files = $this->files();
        if (!in_array(self::MAIN, $files, true)) {
            throw new RuntimeException('The plugin has no ' . self::MAIN . ' in ' . $this->source . '.');
        }
        if (!is_file($tool)) {
            throw new RuntimeException('The backup tool "' . $tool . '" is missing.');
        }
        $directory = dirname($zipPath);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create the directory \"$directory\".");
        }
        @unlink($zipPath);
        $names = [];
        try {
            $zip = ZipWriter::create($zipPath, 9);
            foreach ($files as $relative) {
                $content = @file_get_contents($this->source . '/' . $relative);
                if ($content === false) {
                    throw new RuntimeException("Cannot read \"$relative\".");
                }
                if ($relative === self::MAIN) {
                    $content = self::stampVersion($content, $version);
                }
                $names[] = self::DIRECTORY . '/' . $relative;
                $zip->addString(self::DIRECTORY . '/' . $relative, $content, $epoch, 0100644);
            }
            $content = @file_get_contents($tool);
            if ($content === false) {
                throw new RuntimeException('Cannot read the backup tool "' . $tool . '".');
            }
            $names[] = self::DIRECTORY . '/' . self::TOOL;
            $zip->addString(self::DIRECTORY . '/' . self::TOOL, $content, $epoch, 0100644);
            $zip->close();
        } catch (ZipException $e) {
            throw new RuntimeException('Cannot write the plugin: ' . $e->getMessage(), 0, $e);
        }

        return $names;
    }
}
