<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use RuntimeException;
use ThreadSafe\Build\RestoreBuilder;
use ThreadSafe\Core\Version;

/**
 * What a person needs to restore a backup somewhere else (docs/PHASE4.md 3.8): the tool `threadsafe-restore.php`, built from this installation's
 * own sources, and a key file with a new random key. The tool is made once per version of the sources and kept in `data/cache/`
 * (building takes about a second, but there is no reason to do it twice). The key is **not** stored: it belongs to the restore, not to
 * this instance.
 */
final class RestoreKit
{
    public function __construct(private readonly string $programDirectory, private readonly string $cacheDirectory)
    {
    }

    /**
     * The path of the built tool; it is built if there is none for the current sources.
     *
     * @throws RuntimeException the tool cannot be built or the cache directory cannot be written
     */
    public function restorePhp(): string
    {
        $builder = new RestoreBuilder($this->programDirectory);
        $fingerprint = [];
        foreach ([...$builder->sourceFiles(), ...array_keys($builder->resources())] as $file) {
            $path = str_starts_with($file, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $file) === 1 ? $file : $this->programDirectory . '/' . $file;
            $fingerprint[] = $file . ':' . (int) @filemtime($path) . ':' . (int) @filesize($path);
        }
        $target = $this->cacheDirectory . '/restore-' . preg_replace('/[^A-Za-z0-9.-]/', '_', Version::CURRENT) . '-' . substr(sha1(implode("\n", $fingerprint)), 0, 12) . '.php';
        if (is_file($target)) {
            return $target;
        }

        if (!is_dir($this->cacheDirectory) && !@mkdir($this->cacheDirectory, 0700, true) && !is_dir($this->cacheDirectory)) {
            throw new RuntimeException('Cannot create the cache directory "' . $this->cacheDirectory . '".');
        }
        $temporary = $target . '.' . bin2hex(random_bytes(3)) . '.tmp';
        try {
            $builder->build($temporary);
            if (!@rename($temporary, $target)) {
                throw new RuntimeException('Cannot store the built restore tool in "' . $this->cacheDirectory . '".');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
        foreach (glob($this->cacheDirectory . '/restore-*.php') ?: [] as $old) {
            if ($old !== $target) {
                @unlink($old);   // the tool of an older version of the sources
            }
        }

        return $target;
    }

    /** A new random key (24 characters of letters and digits). */
    public static function newKey(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', 'xZ'), '=');
    }

    /** The content of `threadsafe-restore-key.php` for a key. */
    public static function keyFile(string $key): string
    {
        return "<?php return '" . $key . "';\n";
    }
}
