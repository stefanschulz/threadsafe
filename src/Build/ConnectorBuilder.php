<?php

declare(strict_types=1);

namespace ThreadSafe\Build;

use RuntimeException;
use ThreadSafe\Archive\ZipException;
use ThreadSafe\Archive\ZipWriter;
use ThreadSafe\Core\Version;

/**
 * Builds the ZIP of a satellite of the connector: the files of `satellites/<kind>/<directory>/` with the version stamped into the main file, and the backup
 * tool `threadsafe-backup.php` of the same version next to them (docs/PHASE13.md 9.12, docs/PHASE15.md 4). Two kinds exist:
 *
 * - `wordpress`: `threadsafe-connector-X.Y.Z.zip`, which WordPress unpacks into `wp-content/plugins/threadsafe-connector/`; the version stands in the header
 *   of the plugin (`* Version:`).
 * - `prestashop`: `threadsafe-connector-prestashop-X.Y.Z.zip`, which the module manager of PrestaShop unpacks into `modules/threadsafeconnector/`; the
 *   version stands in the constructor of the module (`$this->version = '...';`).
 *
 * The top directory of the archive is the name that the CMS expects. Like the other packages it is written by our own writer with one time for every
 * file, so two builds of one input are the same bytes.
 */
final class ConnectorBuilder
{
    /** The top directory of the WordPress plugin. */
    public const string DIRECTORY = 'threadsafe-connector';

    public const string TOOL = 'threadsafe-backup.php';

    /**
     * What differs between the satellites: the directory of the source below `satellites/<kind>/` (also the top directory of the archive), the main file,
     * the pattern of the line that holds the version (the groups around the version stay as they are) and the start of the name of the package.
     */
    private const array KINDS = [
        'wordpress' => ['directory' => 'threadsafe-connector', 'main' => 'threadsafe-connector.php', 'version' => '/^( \* Version:\h+)\S+()/m', 'package' => 'threadsafe-connector-'],
        'prestashop' => ['directory' => 'threadsafeconnector', 'main' => 'threadsafeconnector.php', 'version' => '/(\$this->version = \')[^\']+(\';)/', 'package' => 'threadsafe-connector-prestashop-'],
    ];

    private readonly string $source;

    private readonly string $directory;

    private readonly string $main;

    /**
     * @param string $root the root of the source tree (the directory that holds `satellites/`)
     * @param string $kind `wordpress` or `prestashop`
     *
     * @throws RuntimeException there is no satellite of that kind
     */
    public function __construct(string $root, private readonly string $kind = 'wordpress')
    {
        $kinds = self::KINDS[$kind] ?? throw new RuntimeException("There is no satellite of the kind \"$kind\".");
        $this->directory = $kinds['directory'];
        $this->main = $kinds['main'];
        $this->source = rtrim(str_replace('\\', '/', $root), '/') . '/satellites/' . $kind . '/' . $this->directory;
    }

    /** `threadsafe-connector-1.1.0.zip` (WordPress) or `threadsafe-connector-prestashop-1.1.0.zip` */
    public static function packageName(string $version, string $kind = 'wordpress'): string
    {
        return (self::KINDS[$kind]['package'] ?? throw new RuntimeException("There is no satellite of the kind \"$kind\".")) . $version . '.zip';
    }

    /** The name of the directory that the archive holds and that the CMS unpacks it into. */
    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * The main file with the version of the release in it.
     *
     * @throws RuntimeException the file has not exactly one line for the version
     */
    public static function stampVersion(string $source, string $version, string $kind = 'wordpress'): string
    {
        if (!Version::isValid($version)) {
            throw new RuntimeException("\"$version\" is not a valid version.");
        }
        $pattern = self::KINDS[$kind]['version'] ?? throw new RuntimeException("There is no satellite of the kind \"$kind\".");
        $count = 0;
        $stamped = preg_replace($pattern, '${1}' . $version . '${2}', $source, -1, $count);
        if ($count !== 1 || $stamped === null) {
            throw new RuntimeException('The main file of the ' . ($kind === 'wordpress' ? 'plugin has not exactly one header line "Version:".' : 'module has not exactly one line "$this->version = \'...\';".'));
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
        if (!in_array($this->main, $files, true)) {
            throw new RuntimeException('The plugin has no ' . $this->main . ' in ' . $this->source . '.');
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
                if ($relative === $this->main) {
                    $content = self::stampVersion($content, $version, $this->kind);
                }
                $names[] = $this->directory . '/' . $relative;
                $zip->addString($this->directory . '/' . $relative, $content, $epoch, 0100644);
            }
            $content = @file_get_contents($tool);
            if ($content === false) {
                throw new RuntimeException('Cannot read the backup tool "' . $tool . '".');
            }
            $names[] = $this->directory . '/' . self::TOOL;
            $zip->addString($this->directory . '/' . self::TOOL, $content, $epoch, 0100644);
            $zip->close();
        } catch (ZipException $e) {
            throw new RuntimeException('Cannot write the plugin: ' . $e->getMessage(), 0, $e);
        }

        return $names;
    }
}
