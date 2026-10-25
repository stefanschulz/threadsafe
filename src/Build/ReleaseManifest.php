<?php

declare(strict_types=1);

namespace ThreadSafe\Build;

use InvalidArgumentException;
use JsonException;
use ThreadSafe\Archive\ZipException;
use ThreadSafe\Archive\ZipReader;
use ThreadSafe\Core\Version;

/**
 * `release.json`, the manifest at the top of a release package (docs/PHASE7.md 3.1): the version, when it was built, what a host
 * must have, the version of the data format the release writes, and every other file of the package with its size and SHA-256.
 * The installer and the update check what they extracted against it; a package that does not match its own manifest is refused.
 *
 * Paths are relative to the package's top directory, with forward slashes; none leaves it.
 */
final readonly class ReleaseManifest
{
    public const string FILE = 'release.json';

    /**
     * @param int                                                                      $built      Unix time of the build (SOURCE_DATE_EPOCH)
     * @param array{required: list<string>, oneOf: list<list<string>>, recommended: list<string>} $extensions
     * @param array<string, array{size: int, sha256: string}>                          $files      path => size and hash
     */
    public function __construct(
        public string $version,
        public int $built,
        public string $php,
        public array $extensions,
        public int $dataFormat,
        public array $files,
    ) {
    }

    /**
     * The requirements of this program, as they stand in the package.
     *
     * @return array{php: string, extensions: array{required: list<string>, oneOf: list<list<string>>, recommended: list<string>}}
     */
    public static function requirements(): array
    {
        return [
            'php' => '8.4',
            'extensions' => [
                'required' => ['hash', 'json', 'mbstring', 'pdo', 'zlib'],
                'oneOf' => [['pdo_mysql', 'mysqli']],
                'recommended' => ['sodium'],
            ],
        ];
    }

    public function toJson(): string
    {
        $files = [];
        foreach ($this->files as $path => $file) {
            $files[$path] = ['size' => $file['size'], 'sha256' => $file['sha256']];
        }
        ksort($files, SORT_STRING);

        return json_encode([
            'version' => $this->version,
            'built' => gmdate('Y-m-d\TH:i:s\Z', $this->built),
            'php' => $this->php,
            'extensions' => $this->extensions,
            'dataFormat' => $this->dataFormat,
            'files' => $files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * @throws InvalidArgumentException the text is not a manifest this program can use
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('The manifest is not valid JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($data)) {
            throw new InvalidArgumentException('The manifest is not a JSON object.');
        }
        $version = $data['version'] ?? null;
        if (!is_string($version) || !Version::isValid($version)) {
            throw new InvalidArgumentException('The manifest has no valid version.');
        }
        $built = is_string($data['built'] ?? null) && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $data['built']) === 1 ? strtotime($data['built']) : false;
        if ($built === false) {
            throw new InvalidArgumentException('The manifest has no valid build time.');
        }
        $php = $data['php'] ?? null;
        if (!is_string($php) || preg_match('/^\d+\.\d+(\.\d+)?$/', $php) !== 1) {
            throw new InvalidArgumentException('The manifest has no valid PHP version.');
        }
        $dataFormat = $data['dataFormat'] ?? null;
        if (!is_int($dataFormat) || $dataFormat < 1) {
            throw new InvalidArgumentException('The manifest has no valid data format.');
        }
        $extensions = self::extensionsFrom($data['extensions'] ?? null);

        $files = [];
        if (!is_array($data['files'] ?? null) || $data['files'] === []) {
            throw new InvalidArgumentException('The manifest lists no files.');
        }
        foreach ($data['files'] as $path => $file) {
            $path = (string) $path;
            if (!self::safePath($path)) {
                throw new InvalidArgumentException("The manifest names an unsafe path: \"$path\".");
            }
            if (!is_array($file) || !is_int($file['size'] ?? null) || $file['size'] < 0 || !is_string($file['sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $file['sha256']) !== 1) {
                throw new InvalidArgumentException("The manifest has no valid size and hash for \"$path\".");
            }
            $files[$path] = ['size' => $file['size'], 'sha256' => $file['sha256']];
        }

        return new self($version, $built, $php, $extensions, $dataFormat, $files);
    }

    /** A path that stays inside the directory it is relative to. */
    public static function safePath(string $path): bool
    {
        if ($path === '' || $path === self::FILE || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0") || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * What is wrong with a package, compared with this manifest: a file that is missing, one that is not listed, one whose size or hash
     * differs, one that is not a file, or any path outside the top directory. Empty if the package is what the manifest says. Reads
     * every file once.
     *
     * @param string $top the top directory of the package inside the archive, with the slash (`threadsafe-0.1.0/`)
     *
     * @return list<string>
     */
    public function problems(ZipReader $zip, string $top): array
    {
        $problems = [];
        $seen = [];
        try {
            foreach ($zip->entries() as $entry) {
                if (!str_starts_with($entry->name, $top)) {
                    $problems[] = "\"{$entry->name}\" is outside the directory \"$top\".";
                    continue;
                }
                $path = substr($entry->name, strlen($top));
                if ($entry->isDirectory) {
                    continue;
                }
                if ($path === self::FILE) {
                    $seen[$path] = true;
                    continue;
                }
                if (!self::safePath($path)) {
                    $problems[] = "\"{$entry->name}\" is not a safe path.";
                    continue;
                }
                if (!isset($this->files[$path])) {
                    $problems[] = "\"$path\" is in the package but not in the manifest.";
                    continue;
                }
                if (isset($seen[$path])) {
                    $problems[] = "\"$path\" is in the package twice.";
                    continue;
                }
                $seen[$path] = true;
                $hash = hash_init('sha256');
                $zip->extract($entry, static function (string $block) use ($hash): void {
                    hash_update($hash, $block);
                });
                if ($entry->size !== $this->files[$path]['size']) {
                    $problems[] = "\"$path\" has {$entry->size} bytes, the manifest says {$this->files[$path]['size']}.";
                } elseif (!hash_equals($this->files[$path]['sha256'], hash_final($hash))) {
                    $problems[] = "\"$path\" does not match its hash in the manifest.";
                }
            }
        } catch (ZipException $e) {
            $problems[] = 'The package is damaged: ' . $e->getMessage();
        }
        foreach (array_keys($this->files) as $path) {
            if (!isset($seen[$path])) {
                $problems[] = "\"$path\" is in the manifest but not in the package.";
            }
        }

        return $problems;
    }

    /**
     * @return array{required: list<string>, oneOf: list<list<string>>, recommended: list<string>}
     */
    private static function extensionsFrom(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('The manifest has no list of extensions.');
        }
        $names = static fn (mixed $list): array => is_array($list) ? array_values(array_filter($list, is_string(...))) : [];
        $oneOf = [];
        foreach (is_array($value['oneOf'] ?? null) ? $value['oneOf'] : [] as $group) {
            $oneOf[] = $names($group);
        }

        return ['required' => $names($value['required'] ?? null), 'oneOf' => $oneOf, 'recommended' => $names($value['recommended'] ?? null)];
    }
}
