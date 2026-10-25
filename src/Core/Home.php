<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

use JsonException;
use RuntimeException;

/**
 * The home of an installation from a release (docs/PHASE7.md 3.2): a directory with `releases/<version>/` (a package, unpacked),
 * `data/` (shared by all releases) and the pointer `current.php`, which names the active release. A new version is a new directory
 * and a new pointer; going back is writing the old pointer again. An installation from a clone has no home.
 *
 * **The pointer** is a PHP file whose first line ends the script, so that fetching it over HTTP prints nothing, followed by JSON:
 *
 *     <?php http_response_code(404); exit; ?>
 *     {"release": "0.1.0", "previous": "0.0.9"}
 *
 * It is read as text and never included: a host that never looks at the time of a file (`opcache.validate_timestamps=0`) would keep an
 * included pointer after an update (PHASE7.md section 8, finding 5). It holds the name of the release, not a path, so that a home
 * can be moved; the name is checked, because it becomes part of a path.
 */
final class Home
{
    public const string POINTER = 'current.php';

    public const string PREFIX = "<?php http_response_code(404); exit; ?>\n";

    /**
     * The home a program directory belongs to (`<home>/releases/<version>`), or null if it is not part of one: a clone, a package
     * that was unpacked by hand, a directory of the tests.
     */
    public static function detect(string $programDirectory): ?string
    {
        $program = rtrim(str_replace('\\', '/', $programDirectory), '/');
        $releases = dirname($program);
        if (basename($releases) !== 'releases' || !Version::isValid(basename($program))) {
            return null;
        }
        $home = dirname($releases);

        return is_file($home . '/' . self::POINTER) ? $home : null;
    }

    /** `<home>/releases/<version>` */
    public static function releaseDirectory(string $home, string $release): string
    {
        if (!Version::isValid($release)) {
            throw new RuntimeException("\"$release\" is not a valid release name.");
        }

        return rtrim($home, '/\\') . '/releases/' . $release;
    }

    /** The content of the pointer file. */
    public static function pointerSource(string $release, ?string $previous = null): string
    {
        if (!Version::isValid($release) || ($previous !== null && !Version::isValid($previous))) {
            throw new RuntimeException('The pointer names a release that is not a valid version.');
        }

        return self::PREFIX . json_encode(['release' => $release, 'previous' => $previous], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * What a pointer file says, or null if it is not a pointer (it does not start with the line that ends the script, is not JSON, or
     * names something that is not a version).
     *
     * @return array{release: string, previous: string|null}|null
     */
    public static function parsePointer(string $content): ?array
    {
        if (!str_starts_with($content, self::PREFIX)) {
            return null;
        }
        try {
            $data = json_decode(substr($content, strlen(self::PREFIX)), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        $release = is_array($data) ? ($data['release'] ?? null) : null;
        $previous = is_array($data) ? ($data['previous'] ?? null) : null;
        if (!is_string($release) || !Version::isValid($release) || ($previous !== null && (!is_string($previous) || !Version::isValid($previous)))) {
            return null;
        }

        return ['release' => $release, 'previous' => $previous];
    }

    /**
     * @return array{release: string, previous: string|null}|null null if there is no pointer or it is damaged
     */
    public static function readPointer(string $home): ?array
    {
        $content = @file_get_contents(rtrim($home, '/\\') . '/' . self::POINTER);

        return is_string($content) ? self::parsePointer($content) : null;
    }

    /**
     * Makes $release the active one. The file is replaced by a rename, so a request sees the old pointer or the new one, never half of it.
     *
     * @throws RuntimeException the release is not unpacked there, or the file cannot be written
     */
    public static function writePointer(string $home, string $release, ?string $previous = null): void
    {
        $source = self::pointerSource($release, $previous);
        if (!is_file(self::releaseDirectory($home, $release) . '/vendor/autoload.php')) {
            throw new RuntimeException("The release $release is not unpacked in \"" . rtrim($home, '/\\') . '/releases".');
        }
        AtomicFile::write(rtrim($home, '/\\') . '/' . self::POINTER, $source);
    }

    private function __construct()
    {
    }
}
