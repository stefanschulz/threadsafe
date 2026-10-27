<?php

declare(strict_types=1);

namespace ThreadSafe\Core;

use ThreadSafe\Profile\Check;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Storage\LocalStorage;
use ThreadSafe\Storage\SpaceCheck;
use ThreadSafe\Web\StateDirectory;

/**
 * What this server, this PHP and this installation allow (docs/PHASE7.md 3.4): the one list of checks that the installation check of
 * the System page, the command `threadsafe doctor`, the installer and the update all use. A check has a subject, a level and a
 * sentence that says what to do. The functions look and report; they change nothing.
 */
final class Environment
{
    /** What an extension is needed for, for the sentence of a check; an extension that is not listed gets a general one. */
    private const array CONSEQUENCES = [
        'zlib' => 'archives cannot be made or read',
        'hash' => 'checksums cannot be made',
        'json' => 'nothing works',
        'mbstring' => 'archives with unusual file names may not be read correctly',
        'pdo' => 'the database cannot be reached',
        'sodium' => 'backups cannot be encrypted, and encrypted backups cannot be opened',
    ];

    /**
     * PHP and the process: version and limits, the extensions this program needs, `open_basedir`, a PHP command line for cron jobs, and
     * whether the process runs as root.
     *
     * @return list<Check>
     */
    public static function runtime(): array
    {
        $checks = [];
        $limit = (float) ini_get('max_execution_time');
        $checks[] = Check::info('PHP', sprintf('%s (%s), memory_limit %s, max_execution_time %s', PHP_VERSION, PHP_SAPI, ini_get('memory_limit') ?: '?', $limit > 0 ? $limit . ' s' : 'no limit'));
        $checks[] = Check::info('Steps', $limit > 0
            ? sprintf('A step of a backup or a check works for about %d s (60 %% of the time limit), then the next request goes on.', (int) round($limit * 0.6))
            : 'There is no time limit; a step works for 20 s.');

        foreach (['zlib' => 'archives cannot be made or read', 'hash' => 'checksums cannot be made', 'json' => 'nothing works'] as $extension => $consequence) {
            if (!extension_loaded($extension)) {
                $checks[] = Check::failure('PHP', "The extension $extension is not loaded; $consequence.");
            }
        }
        if (!extension_loaded('mbstring')) {
            $checks[] = Check::warning('PHP', 'The extension mbstring is not loaded; archives with unusual file names may not be read correctly.');
        }
        if (!extension_loaded('pdo_mysql') && !extension_loaded('mysqli')) {
            $checks[] = Check::warning('PHP', 'Neither pdo_mysql nor mysqli is loaded; sites with a database cannot be backed up.');
        }
        $basedir = (string) ini_get('open_basedir');
        if ($basedir !== '') {
            $checks[] = Check::warning('PHP', "open_basedir is set ($basedir); the site directories and the storage directories must be inside it.");
        }

        $cli = PhpBinary::cli();
        $checks[] = $cli !== null
            ? Check::ok('PHP command line', "found: $cli (a cron job can run backups in one consistent snapshot)")
            : Check::warning('PHP command line', 'No PHP command line binary was found. Backups from the web interface work, but a cron job (a later phase) and the command line need one.');

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $checks[] = Check::warning('Process', 'The web server runs this as root. A flaw would give access to the whole server; run it as an unprivileged user.');
        }

        return $checks;
    }

    /** Whether the page that asks was served over a connection that protects the password. */
    public static function connection(bool $https, bool $local): Check
    {
        return $https
            ? Check::ok('Connection', 'This page was served over https.')
            : ($local ? Check::info('Connection', 'This page was served over plain http from this machine itself.') : Check::warning('Connection', 'This page was served over plain http: the password and the backups cross the network in clear text. Use https.'));
    }

    /**
     * The data directory: that it can be written, who can read it, that it is closed to the web server, and that no setup key is left in it.
     * A directory that does not exist yet is fine if it can be made.
     *
     * @return list<Check>
     */
    public static function dataDirectory(string $data): array
    {
        if (!OpenBasedir::permits($data)) {
            return [Check::failure('Data directory', "\"$data\" is outside the directories that this host lets PHP use (open_basedir: " . implode(', ', OpenBasedir::allowed()) . '); profiles, runs and sessions cannot be stored there.')];
        }
        if (!@is_dir($data)) {
            $parent = dirname($data);

            return OpenBasedir::permits($parent) && @is_dir($parent) && @is_writable($parent)
                ? [Check::info('Data directory', "\"$data\" does not exist yet; it is made when something is stored (profiles, runs, sessions).")]
                : [Check::failure('Data directory', "\"$data\" does not exist and cannot be made; profiles, runs and sessions cannot be stored.")];
        }
        if (!is_writable($data)) {
            return [Check::failure('Data directory', "\"$data\" is not writable; profiles, runs and sessions cannot be stored.")];
        }
        $checks = [Check::ok('Data directory', "\"$data\" is writable")];
        if (DIRECTORY_SEPARATOR === '/' && (fileperms($data) & 0077) !== 0) {
            $checks[] = Check::warning('Data directory', sprintf('The permissions are %04o; they hold profiles with database passwords and should be 0700.', fileperms($data) & 0777));
        }
        if (!is_file($data . '/.htaccess')) {
            $checks[] = Check::warning('Data directory', 'It has no .htaccess; unless it lies outside the web root, make sure the web server does not serve it.');
        }
        if (is_file($data . '/setup-key.php')) {
            $checks[] = Check::warning('Setup', 'The setup key file setup-key.php is still in the data directory; delete it.');
        }

        return $checks;
    }

    /**
     * The site directories and the storage of every profile.
     *
     * @return list<Check>
     */
    public static function profiles(string $data): array
    {
        $checks = [];
        foreach ((new ProfileStore($data . '/profiles'))->all() as $profile) {
            $subject = 'Profile ' . $profile->id;
            if (!is_dir($profile->path)) {
                $checks[] = Check::failure($subject, 'The site directory "' . $profile->path . '" does not exist.');
            } else {
                foreach (['restore.php', 'restore-key.php', StateDirectory::NAME] as $leftover) {
                    if (file_exists($profile->path . '/' . $leftover)) {
                        $checks[] = Check::warning($subject, "\"$leftover\" is in the site directory. It belongs to a restore; remove it if the restore is done (a restore tool left on a web server is a risk).");
                    }
                }
            }

            if (!is_dir($profile->storage)) {
                $checks[] = Check::info($subject, 'The storage directory "' . $profile->storage . '" does not exist yet; the first backup creates it.');
                continue;
            }
            if (!is_writable($profile->storage)) {
                $checks[] = Check::failure($subject, 'The storage directory "' . $profile->storage . '" is not writable.');
                continue;
            }
            $free = @disk_free_space($profile->storage);
            $checks[] = (new LocalStorage($profile->storage))->isProtected()
                ? Check::ok($subject, 'Storage "' . $profile->storage . '" is writable and has its protection files' . ($free !== false ? ', ' . SpaceCheck::format((int) $free) . ' free' : ''))
                : Check::warning($subject, 'Storage "' . $profile->storage . '" has no protection files; keep it outside the web root or make the web server refuse it.');
        }

        return $checks;
    }

    /**
     * How this program is installed: its version, from a release or from a clone, and for a release whether the home can be written (an
     * update from the web interface has to write there) and which release is active.
     *
     * @param string|null $program the program directory to look at; default: the one that runs
     *
     * @return list<Check>
     */
    public static function installation(?string $program = null): array
    {
        $program ??= Paths::program();
        $home = Home::detect($program);
        if ($home === null) {
            return [Check::info('Installation', 'ThreadSafe ' . Version::CURRENT . ' in "' . $program . '", installed from a clone: update it with git pull and composer install.')];
        }
        $checks = [Check::info('Installation', 'ThreadSafe ' . Version::CURRENT . ' from a release; the home is "' . $home . '".')];
        $pointer = Home::readPointer($home);
        if ($pointer === null) {
            $checks[] = Check::failure('Installation', 'The pointer to the active release (' . $home . '/' . Home::POINTER . ') is missing or damaged; the web interface cannot start. Restore the file from a backup of the home directory, or install again.');
        } elseif ($pointer['release'] !== basename($program)) {
            $checks[] = Check::info('Installation', 'The active release is ' . $pointer['release'] . '; this command runs ' . basename($program) . '.');
        } elseif ($pointer['previous'] !== null && is_dir(Home::releaseDirectory($home, $pointer['previous']))) {
            $checks[] = Check::info('Installation', 'The previous release ' . $pointer['previous'] . ' is kept for a roll-back.');
        }
        foreach ([$home => 'The home directory', $home . '/releases' => 'The releases directory'] as $directory => $label) {
            if (!is_writable($directory)) {
                $checks[] = Check::warning('Installation', "$label \"$directory\" is not writable for this process; an update cannot be installed from here (the web server's user must be able to write there).");
            }
        }

        return $checks;
    }

    /**
     * Whether this host meets the requirements of a release (`release.json`): the PHP version and the extensions. A missing extension
     * the release cannot run without is a failure, and says what to ask the host for.
     *
     * @param array{required: list<string>, oneOf: list<list<string>>, recommended: list<string>} $extensions
     *
     * @return list<Check>
     */
    public static function requirements(string $version, string $php, array $extensions): array
    {
        $subject = 'Version ' . $version;
        $checks = [];
        if (version_compare(PHP_VERSION, $php, '<')) {
            $checks[] = Check::failure($subject, "It needs PHP $php or newer; this host has PHP " . PHP_VERSION . '. Ask your host to switch the PHP version of this site, or stay on the version you have.');
        }
        foreach ($extensions['required'] as $extension) {
            if (!extension_loaded($extension)) {
                $checks[] = Check::failure($subject, "It needs the PHP extension $extension (" . (self::CONSEQUENCES[$extension] ?? 'it does not work without it') . '). Ask your host to enable it.');
            }
        }
        foreach ($extensions['oneOf'] as $group) {
            if ($group !== [] && array_filter($group, extension_loaded(...)) === []) {
                $checks[] = Check::warning($subject, 'It needs one of the PHP extensions ' . implode(', ', $group) . ' to back up sites with a database; none is loaded. Ask your host to enable one.');
            }
        }
        foreach ($extensions['recommended'] as $extension) {
            if (!extension_loaded($extension)) {
                $checks[] = Check::warning($subject, "It recommends the PHP extension $extension; without it " . (self::CONSEQUENCES[$extension] ?? 'some features are missing') . '.');
            }
        }
        if ($checks === []) {
            $checks[] = Check::ok($subject, "This host meets its requirements (PHP $php or newer, the extensions it needs).");
        }

        return $checks;
    }

    private function __construct()
    {
    }
}
