<?php

declare(strict_types=1);

namespace ThreadSafe\Profile;

use ThreadSafe\Backup\SchemaReader;
use ThreadSafe\Core\OpenBasedir;
use ThreadSafe\Core\PhpBinary;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\ConnectionFailed;
use ThreadSafe\Database\DatabaseError;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Plugin\PluginRegistry;

/**
 * Looks at everything a backup of a profile depends on and reports what it finds, without changing anything: the site and
 * what the website type makes of it, the database (reachable, supported, privileges, snapshot), the storage directory and
 * the PHP environment. Problems are reported, never worked around.
 */
final class ConnectionTest
{
    /** The warning about a storage inside the site; it also stands in the report of the backups that were made with it (see `Restore\Preflight`). */
    public const string STORAGE_INSIDE_SITE = 'The storage directory is inside the site. It is left out of the backup automatically, but a web server could serve its files: keep it outside the web root or make sure it is blocked.';

    private const array NEEDED_PRIVILEGES = ['SELECT', 'SHOW VIEW', 'TRIGGER'];

    public function __construct(private readonly PluginRegistry $plugins, private readonly ?string $dataDirectory = null)
    {
    }

    public function run(SiteProfile $profile): TestReport
    {
        $checks = [];
        $resolved = null;
        try {
            $resolved = (new ProfileResolver($this->plugins, $this->dataDirectory))->resolve($profile);
            $checks[] = Check::ok('Site', $resolved->site->describe() . ' in ' . $resolved->root);
            $admin = $resolved->site->detail('adminDirectory');
            if ($admin !== null) {
                $checks[] = Check::info('Site', "back office folder: $admin");
            }
            $checks[] = Check::info('Filters', sprintf('%d paths and %d tables (structure only) are left out%s.', count($resolved->excludePaths), count($resolved->structureOnly), $resolved->literalExcludes === [] ? '' : '; also ' . implode(', ', $resolved->literalExcludes) . ' (inside the site)'));
        } catch (ProfileException $e) {
            $checks[] = Check::failure('Site', $e->getMessage());
        }

        if ($resolved !== null) {
            array_push($checks, ...$this->database($profile, $resolved->database));
        }
        array_push($checks, ...$this->storage($profile, $resolved?->root));
        array_push($checks, ...$this->environment($resolved !== null ? $resolved->database !== null : $profile->database !== null));

        return new TestReport($profile->id, $checks);
    }

    /**
     * @return list<Check>
     */
    private function database(SiteProfile $profile, ?DatabaseSettings $settings): array
    {
        if ($settings === null) {
            return [Check::info('Database', 'none: only the files are backed up')];
        }
        $source = $profile->database !== null ? 'from the profile' : "from the site's configuration";
        $checks = [];
        try {
            $db = Connection::open($settings);
        } catch (ConnectionFailed $e) {
            return [Check::failure('Database', $e->getMessage())];
        }
        $checks[] = Check::ok('Database', sprintf('"%s" on %s:%d (%s), prefix "%s", PHP interface %s', $settings->name, $settings->host, $settings->port, $source, $settings->prefix, $db->driverName()));
        if (($problem = $db->server->problem()) !== null) {
            $checks[] = Check::failure('Database server', $problem);

            return $checks;
        }
        $checks[] = Check::ok('Database server', $db->server->describe());

        $checks[] = $this->privileges($db, $settings);
        $checks[] = $this->snapshot($db);

        try {
            $facts = $db->select("SELECT COUNT(*) AS n, IFNULL(SUM(DATA_LENGTH + INDEX_LENGTH), 0) AS b FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")[0] ?? ['n' => 0, 'b' => 0];
            $checks[] = Check::info('Database size', sprintf('%d tables, about %s', (int) $facts['n'], self::megabytes((int) $facts['b'])));
            $unsupported = (new SchemaReader($db))->unsupported();
            if ($unsupported !== []) {
                $checks[] = Check::warning('Database objects', sprintf(
                    '%d stored routine(s), event(s) or sequence(s) are not part of a backup: %s',
                    count($unsupported),
                    implode(', ', array_map(static fn (array $o): string => $o['type'] . ' ' . $o['name'], array_slice($unsupported, 0, 5))) . (count($unsupported) > 5 ? ', ...' : ''),
                ));
            }
            $packet = (int) $db->scalar('SELECT @@max_allowed_packet');
            $checks[] = Check::info('max_allowed_packet', self::megabytes($packet) . ' (a cell above that cannot be restored by SQL)');
        } catch (DatabaseError $e) {
            $checks[] = Check::warning('Database', 'Could not read the size of the database: ' . $e->getMessage());
        }

        return $checks;
    }

    private function privileges(Connection $db, DatabaseSettings $settings): Check
    {
        try {
            $lines = [];
            foreach ($db->select('SHOW GRANTS FOR CURRENT_USER()') as $row) {
                $value = reset($row);
                if (is_string($value)) {
                    $lines[] = $value;
                }
            }
        } catch (DatabaseError $e) {
            return Check::warning('Privileges', 'Could not read the grants of the database user: ' . $e->getMessage());
        }
        $privileges = Grants::privileges($lines, $settings->name);
        $missing = Grants::missing($privileges, self::NEEDED_PRIVILEGES);
        if ($missing === []) {
            return Check::ok('Privileges', implode(', ', self::NEEDED_PRIVILEGES) . ' on the database');
        }
        if ($privileges === []) {
            return Check::warning('Privileges', 'Could not tell which privileges the user has on this database (only grants on single tables?); a backup needs ' . implode(', ', self::NEEDED_PRIVILEGES) . '.');
        }
        if (in_array('SELECT', $missing, true)) {
            return Check::failure('Privileges', 'The database user lacks SELECT: ' . implode(', ', $missing));
        }

        return Check::warning('Privileges', 'The database user lacks ' . implode(', ', $missing) . ': views or triggers cannot be dumped.');
    }

    private function snapshot(Connection $db): Check
    {
        try {
            $db->startSnapshot();
            $db->endSnapshot();
        } catch (DatabaseError $e) {
            return Check::warning('Snapshot', 'A consistent snapshot cannot be started (' . $e->getMessage() . '); the dump is then consistent per table only.');
        }

        return Check::ok('Snapshot', 'a consistent snapshot of all InnoDB tables can be started (command line backups)');
    }

    /**
     * The storage directory: can it be used or made, and is there room. A path that `open_basedir` does not allow is named as that, and no path outside
     * the allowed ones is touched (it would be a warning, and say nothing useful: "/" is not a directory).
     *
     * @param string|null $openBasedir for tests: the setting to check against; default: the one in force
     *
     * @return list<Check>
     */
    public function storage(SiteProfile $profile, ?string $siteRoot, ?string $openBasedir = null): array
    {
        $checks = [];
        $storage = $profile->storage;
        $existing = $storage;
        if (!OpenBasedir::permits($storage, $openBasedir)) {
            return [Check::failure('Storage', "\"$storage\" is outside the directories that this host lets PHP use (open_basedir: " . implode(', ', OpenBasedir::allowed($openBasedir)) . '). '
                . 'Choose a directory inside one of them, outside the web root (a "private" directory next to the document root is common), or ask the host to allow this one.')];
        }
        while (!@file_exists($existing) && dirname($existing) !== $existing && OpenBasedir::permits(dirname($existing), $openBasedir)) {
            $existing = dirname($existing);
        }

        if (!OpenBasedir::permits($existing, $openBasedir)) {
            $checks[] = Check::failure('Storage', "PHP cannot make \"$storage\": \"$existing\" is outside the directories that this host lets PHP use (open_basedir: " . implode(', ', OpenBasedir::allowed($openBasedir)) . ').');
        } elseif (!is_dir($existing)) {
            $checks[] = Check::failure('Storage', "\"$storage\" cannot be used or created: \"$existing\" is not a directory.");
        } elseif (is_dir($storage)) {
            $probe = @tempnam($storage, '.ts-probe-');
            if ($probe === false) {
                $checks[] = Check::failure('Storage', "\"$storage\" is not writable.");
            } else {
                @unlink($probe);
                $checks[] = Check::ok('Storage', "\"$storage\" exists and is writable");
            }
        } elseif (is_writable($existing)) {
            $checks[] = Check::ok('Storage', "\"$storage\" does not exist yet and can be created (inside \"$existing\")");
        } else {
            $checks[] = Check::failure('Storage', "\"$storage\" does not exist and \"$existing\" is not writable, so it cannot be created.");
        }

        $free = @disk_free_space($existing);
        if ($free !== false) {
            $checks[] = Check::info('Storage', 'free space: ' . self::megabytes((int) $free));
        }

        if ($siteRoot !== null && ProfileResolver::relativeInside($siteRoot, $storage) !== null && !self::isToolStorage($storage)) {
            $checks[] = Check::warning('Storage', self::STORAGE_INSIDE_SITE);
        }

        return $checks;
    }

    /**
     * The storage of the single-file backup tool (`.threadsafe-backup/storage-<32 hex digits>`, made by the tool's workspace class): it lies in the site on
     * purpose, is closed by `.htaccess` and an empty `index.html`, has an unguessable name, and the tool refuses to start while its probe shows
     * that the directory can be fetched. The warning "a web server could serve its files" would only frighten the person who restores the
     * backup (it is part of the backup's own report), so it is not given for this directory. (The pattern is repeated here because this class is
     * part of the restore tool, which does not carry the workspace class; a test keeps the two in step.)
     */
    public static function isToolStorage(string $storage): bool
    {
        $path = str_replace('\\', '/', rtrim($storage, '/\\'));

        return preg_match('#/\.threadsafe-backup/storage-[0-9a-f]{32}$#D', $path) === 1;
    }

    /**
     * What PHP and the host allow, for the test of a profile. Public for the tests of `open_basedir`.
     *
     * @param string|null $openBasedir the setting to look at; default: the one in force
     *
     * @return list<Check>
     */
    public function environment(bool $needsDatabase, ?string $openBasedir = null): array
    {
        $checks = [Check::info('PHP', sprintf('%s (%s), memory_limit %s, max_execution_time %s', PHP_VERSION, PHP_SAPI, ini_get('memory_limit') ?: '?', ini_get('max_execution_time') ?: '0'))];

        foreach (['zlib', 'hash'] as $extension) {
            if (!extension_loaded($extension)) {
                $checks[] = Check::failure('PHP', "The extension $extension is not loaded; archives cannot be made without it.");
            }
        }
        if ($needsDatabase && !extension_loaded('pdo_mysql') && !extension_loaded('mysqli')) {
            $checks[] = Check::failure('PHP', 'Neither pdo_mysql nor mysqli is loaded.');
        }

        // not a warning: the site path and the storage are checked against it above, and one outside it is a failure there
        $basedir = $openBasedir ?? (string) ini_get('open_basedir');
        if ($basedir !== '') {
            $checks[] = Check::info('PHP', "open_basedir is set ($basedir); the site path and the storage are checked against it.");
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $blocked = array_values(array_intersect(['exec', 'proc_open', 'shell_exec', 'popen'], $disabled));
        $checks[] = $blocked === []
            ? Check::info('Processes', 'exec and proc_open are allowed')
            : Check::info('Processes', 'disabled by the host: ' . implode(', ', $blocked) . '. The web interface cannot start a command line job; backups run in ticks, or from a cron job that calls the command line.');

        $cli = PhpBinary::cli();
        $checks[] = $cli !== null
            ? Check::info('PHP command line', "found: $cli (a scheduled backup can run as a process of its own, in one consistent snapshot)")
            : Check::info('PHP command line', PhpBinary::missingNote());

        return $checks;
    }

    private static function megabytes(int $bytes): string
    {
        return $bytes >= 1073741824 ? sprintf('%.1f GB', $bytes / 1073741824) : sprintf('%.0f MB', $bytes / 1048576);
    }
}
