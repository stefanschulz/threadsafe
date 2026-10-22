<?php

declare(strict_types=1);

namespace ThreadSafe\Admin;

use ThreadSafe\Core\PhpBinary;
use ThreadSafe\Profile\Check;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Storage\LocalStorage;
use ThreadSafe\Storage\SpaceCheck;
use ThreadSafe\Web\StateDirectory;

/**
 * The installation check (docs/PHASE4.md 3.9): what this server, this PHP and this installation allow, and what is exposed that
 * should not be. It looks and reports; it changes nothing except the probe file for the question whether `data/` can be fetched.
 */
final class SystemChecks
{
    /** What the probe file holds; the page's script fetches the file from where `data/` would be served and compares. */
    public const string PROBE = 'threadsafe-data-probe';

    public function __construct(private readonly Services $services)
    {
    }

    /**
     * @return list<Check>
     */
    public function run(bool $https, bool $local): array
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

        $checks[] = $https
            ? Check::ok('Connection', 'This page was served over https.')
            : ($local ? Check::info('Connection', 'This page was served over plain http from this machine itself.') : Check::warning('Connection', 'This page was served over plain http: the password and the backups cross the network in clear text. Use https.'));

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $checks[] = Check::warning('Process', 'The web server runs this as root. A flaw would give access to the whole server; run it as an unprivileged user.');
        }

        array_push($checks, ...$this->data());
        array_push($checks, ...$this->profiles());

        return $checks;
    }

    /**
     * @return list<Check>
     */
    private function data(): array
    {
        $data = $this->services->data;
        $checks = [];
        if (!is_dir($data) || !is_writable($data)) {
            return [Check::failure('Data directory', "\"$data\" is not writable; profiles, runs and sessions cannot be stored.")];
        }
        $checks[] = Check::ok('Data directory', "\"$data\" is writable");
        if (DIRECTORY_SEPARATOR === '/' && (fileperms($data) & 0077) !== 0) {
            $checks[] = Check::warning('Data directory', sprintf('The permissions are %04o; they hold profiles with database passwords and should be 0700.', fileperms($data) & 0777));
        }
        if (!is_file($data . '/.htaccess')) {
            $checks[] = Check::warning('Data directory', 'It has no .htaccess; unless it lies outside the web root, make sure the web server does not serve it.');
        }
        if (is_file($this->services->setupKeyFile())) {
            $checks[] = Check::warning('Setup', 'The setup key file ' . basename($this->services->setupKeyFile()) . ' is still in the data directory; delete it.');
        }

        return $checks;
    }

    /**
     * @return list<Check>
     */
    private function profiles(): array
    {
        $checks = [];
        foreach ((new ProfileStore($this->services->data . '/profiles'))->all() as $profile) {
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
     * Writes the file the browser is asked for to find out whether `data/` can be fetched from outside. Where it would be served
     * is not known to PHP (it depends on the document root), so the page's script asks for the file at the addresses where it would
     * be if the program directory lay below the web root.
     *
     * @return bool the file is there
     */
    public function writeProbe(): bool
    {
        $file = $this->services->data . '/probe.txt';
        if (!is_file($file) || trim((string) @file_get_contents($file)) !== self::PROBE) {
            @file_put_contents($file, self::PROBE . "\n");
        }

        return is_file($file);
    }
}
