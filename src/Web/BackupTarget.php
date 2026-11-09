<?php

declare(strict_types=1);

namespace ThreadSafe\Web;

use ThreadSafe\Backup\Filters;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Profile\EncryptionSettings;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\ProfileResolver;
use ThreadSafe\Profile\SitePath;
use ThreadSafe\Profile\SiteProfile;

/**
 * What the backup tool may back up and what it must leave out (docs/PHASE12.md 3.3, 3.4), and the profile it makes of it.
 *
 * The tool reads whatever the user of PHP can read, and whoever holds its key can make it do that; so the directory is **the one the tool is
 * in or one above it**. Its own files are never part of the archive: itself, its key file, its directory and the directory of the restore
 * tool, which may lie next to it.
 */
final class BackupTarget
{
    /** The default size of a part: small enough that a download can be repeated per part. */
    public const int PART_SIZE = 524288000;

    /**
     * @param string $toolDirectory the directory the tool is in
     * @param string $script        the file name of the tool
     * @param string $keyFile       the file name of its key file
     * @param string|null $workspace the directory of a workspace that lies elsewhere than next to the tool ({@see BackupWorkspace::at()}); it is never
     *                               part of the archive either: it holds the profile (with a database password), the pairing secret and the archives
     */
    public function __construct(
        private readonly string $toolDirectory,
        private readonly string $script = 'threadsafe-backup.php',
        private readonly string $keyFile = 'threadsafe-backup-key.php',
        private readonly ?string $workspace = null,
    ) {
    }

    /** The directory that is offered first: the one the tool is in. */
    public function defaultRoot(): string
    {
        return realpath($this->toolDirectory) ?: $this->toolDirectory;
    }

    /**
     * Why a directory may not be backed up, or null if it may.
     */
    public function problem(string $path, ?string $openBasedir = null): ?string
    {
        $problem = SitePath::problem($path, $openBasedir);
        if ($problem !== null) {
            return $problem;
        }
        $root = realpath($path);
        $tool = realpath($this->toolDirectory);
        if ($root === false || $tool === false) {
            return 'PHP cannot resolve the directory.';
        }
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $tool = rtrim(str_replace('\\', '/', $tool), '/') . '/';
        if (!str_starts_with($tool, $root)) {
            return 'this tool can back up the directory it is in (' . rtrim($tool, '/') . ') or one above it, and no other: whoever holds its key could otherwise make it read '
                . 'any directory that PHP can read. Put the tool into the site you want to back up.';
        }

        return null;
    }

    /**
     * The real path of a directory that may be backed up.
     *
     * @throws ProfileException the directory cannot be used; the message says why
     */
    public function resolve(string $path, ?string $openBasedir = null): string
    {
        $problem = $this->problem($path, $openBasedir);
        if ($problem !== null) {
            throw new ProfileException("The site path \"$path\" cannot be backed up: $problem");
        }

        return SitePath::resolve($path, $openBasedir);
    }

    /**
     * The patterns (anchored at the site root) of what is the tool's own: empty if the tool is not inside the directory. A character that a
     * pattern means something by (`*`, `?`, a backslash) in a file name is written as `?`, which matches that one character: the tool then
     * leaves out a file that merely looks like its own, and never takes one of its own in.
     *
     * @return list<string>
     */
    public function selfExcludes(string $root): array
    {
        $tool = realpath($this->toolDirectory);
        if ($tool === false) {
            return [];
        }
        $root = realpath($root) ?: $root;
        if (rtrim(str_replace('\\', '/', $root), '/') === rtrim(str_replace('\\', '/', $tool), '/')) {
            $prefix = '';
        } else {
            $prefix = ProfileResolver::relativeInside($root, $tool);
            if ($prefix === null) {
                return [];
            }
            $prefix .= '/';
        }

        $patterns = [];
        foreach ([$this->script, $this->keyFile] as $file) {
            $patterns[] = self::pattern($prefix . $file);
        }
        foreach ([BackupWorkspace::NAME, StateDirectory::NAME] as $directory) {
            $patterns[] = self::pattern($prefix . $directory) . '/';
        }
        // a workspace that lies elsewhere in the site (a plugin keeps it in wp-content/uploads): its data directory holds the profile with the database
        // password and the pairing secret, and its storage the archives
        if ($this->workspace !== null) {
            $inside = ProfileResolver::relativeInside($root, $this->workspace);
            if ($inside !== null) {
                $patterns[] = self::pattern($inside) . '/';
            }
        }

        return array_values(array_unique($patterns));
    }

    /**
     * The one profile of the tool: the site, its storage in the tool's workspace, the tool's own files left out. The database is read from the
     * application when it is null.
     *
     * @param list<string> $exclude more patterns to leave out (relative to the site root)
     *
     * @throws ProfileException a pattern is not valid
     */
    public function profile(
        string $root,
        string $type,
        string $storage,
        ?DatabaseSettings $database = null,
        array $exclude = [],
        ?int $partSize = null,
        ?EncryptionSettings $encryption = null,
        string $note = '',
    ): SiteProfile {
        $patterns = array_values(array_unique([...$this->selfExcludes($root), ...$exclude]));
        foreach ($patterns as $pattern) {
            if (($problem = Filters::problem($pattern)) !== null) {
                throw new ProfileException("The pattern \"$pattern\" is not valid: $problem.");
            }
        }

        return new SiteProfile(
            id: BackupWorkspace::PROFILE,
            name: BackupWorkspace::PROFILE,
            type: $type,
            path: $root,
            database: $database,
            storage: $storage,
            excludePaths: $patterns,
            partSize: $partSize ?? self::PART_SIZE,
            note: $note,
            encryption: $encryption,
        );
    }

    private static function pattern(string $relative): string
    {
        return '/' . strtr($relative, ['*' => '?', '?' => '?', '\\' => '?']);
    }
}
