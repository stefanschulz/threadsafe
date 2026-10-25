<?php

declare(strict_types=1);

namespace ThreadSafe\Install;

use ThreadSafe\Core\Home;
use ThreadSafe\Core\Layout;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\Task;
use ThreadSafe\Engine\TickOutcome;
use ThreadSafe\Update\DataFormat;
use ThreadSafe\Web\StateDirectory;

/**
 * The installation as a task in ticks (docs/PHASE7.md 3.3), so that it fits the time limit of a request and goes on after a cut-off:
 *
 *   1. **prepare:** the home and its directories (`releases/`, `data/` closed to the web, `bin/`);
 *   2. **extract:** the package into `releases/<version>.partial/`, entry by entry, each file checked against its size and SHA-256 in
 *      `release.json` while it is written; a file that is not in the manifest, a path that leaves the directory or an entry that is no
 *      plain file stops the installation;
 *   3. **finish:** the release gets its name, then `bin/threadsafe`, the stub and `.htaccess` of the document root, the setup key and,
 *      **last**, the pointer `current.php`. Until the pointer exists the stub says that nothing is installed, so a cut-off leaves nothing
 *      that half works.
 *
 * Every step can be repeated: the state remembers where the extraction was, and a file that was cut off is written again.
 */
final class InstallRun implements Task
{
    public function id(): string
    {
        return 'install';
    }

    /**
     * @param array<string, mixed> $parameters `file` (the installer), `docroot`, `home`
     *
     * @return array<string, mixed>
     */
    public function initialState(array $parameters): array
    {
        $file = (string) ($parameters['file'] ?? '');
        $package = InstallPackage::open($file);

        return [
            'phase' => 'prepare',
            'file' => $file,
            'docroot' => rtrim(str_replace('\\', '/', (string) ($parameters['docroot'] ?? dirname($file))), '/'),
            'home' => rtrim(str_replace('\\', '/', (string) ($parameters['home'] ?? '')), '/'),
            'version' => $package->manifest->version,
            ...Unpacker::initialState($package),
            'setupKey' => '',
        ];
    }

    public function tick(array $state, Budget $budget): TickOutcome
    {
        return match ($state['phase'] ?? '') {
            'prepare' => $this->prepare($state),
            'extract' => $this->extract($state, $budget),
            'finish' => $this->finish($state),
            default => new TickOutcome($state, true, 'Installed.'),
        };
    }

    // ------------------------------------------------------------------------------------------------- prepare

    /**
     * @param array<string, mixed> $state
     */
    private function prepare(array $state): TickOutcome
    {
        $home = (string) $state['home'];
        $docroot = (string) $state['docroot'];
        if (($problem = Places::documentRootProblem($docroot)) !== null) {
            throw new InstallException($problem);
        }
        // a second try after a cut-off finds the directories it made itself: that is fine as long as nothing has been extracted into a release
        if (!is_dir($home) && ($problem = Places::problem($home, $docroot)) !== null) {
            throw new InstallException($problem);
        }
        if (is_dir($home . '/releases/' . $state['version'])) {
            throw new InstallException("The release {$state['version']} is already in \"$home/releases\". Choose another home, or remove the directory if it is a leftover.");
        }
        foreach ([$home, $home . '/releases', $home . '/bin'] as $directory) {
            if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new InstallException("Cannot create the directory \"$directory\".");
            }
        }
        StateDirectory::prepare($home . '/data');
        if (Places::isInside($home, $docroot)) {
            self::write($home . '/.htaccess', Layout::closedHtaccess());
            self::write($home . '/index.html', '');
        }
        $partial = $home . '/releases/' . $state['version'] . '.partial';
        if (!is_dir($partial) && !@mkdir($partial, 0755) && !is_dir($partial)) {
            throw new InstallException("Cannot create the directory \"$partial\".");
        }

        $state['phase'] = 'extract';

        return new TickOutcome($state, false, 'The directories are ready.');
    }

    // ------------------------------------------------------------------------------------------------- extract

    /**
     * @param array<string, mixed> $state
     */
    private function extract(array $state, Budget $budget): TickOutcome
    {
        [$state, $complete] = (new Unpacker())->unpack($state, (string) $state['file'], $state['home'] . '/releases/' . $state['version'] . '.partial', $budget);
        if (!$complete) {
            return new TickOutcome($state, false, sprintf('Unpacking: %d of %d files', $state['files'], $state['expected']));
        }
        $state['phase'] = 'finish';

        return new TickOutcome($state, false, sprintf('Unpacked %d files.', $state['files']));
    }
    // ------------------------------------------------------------------------------------------------- finish

    /**
     * @param array<string, mixed> $state
     */
    private function finish(array $state): TickOutcome
    {
        $home = (string) $state['home'];
        $docroot = (string) $state['docroot'];
        $version = (string) $state['version'];
        $release = Home::releaseDirectory($home, $version);
        $partial = $release . '.partial';

        if (is_dir($partial)) {
            if (!is_file($partial . '/vendor/autoload.php')) {
                throw new InstallException('The package has no vendor/autoload.php; it cannot run. The package is refused.');
            }
            if (!@rename($partial, $release)) {
                throw new InstallException("Cannot rename \"$partial\" to \"$release\".");
            }
        }
        if (!is_file($release . '/vendor/autoload.php')) {
            throw new InstallException("The release $version is not in \"$home/releases\".");
        }

        self::write($home . '/bin/threadsafe', Layout::command($home));
        @chmod($home . '/bin/threadsafe', 0755);
        self::write($docroot . '/index.php', Layout::stub($home), true);
        self::write($docroot . '/.htaccess', Layout::documentRootHtaccess(), true);

        $key = (string) $state['setupKey'] !== '' ? (string) $state['setupKey'] : bin2hex(random_bytes(12));
        self::write($home . '/data/setup-key.php', "<?php return '" . $key . "';\n");
        @chmod($home . '/data/setup-key.php', 0600);

        // the data directory starts in the format of this release, so that the first start has nothing to migrate
        DataFormat::write($home . '/data', InstallPackage::open((string) $state['file'])->manifest->dataFormat, $version);
        Home::writePointer($home, $version);

        $state['setupKey'] = $key;
        $state['phase'] = 'done';

        return new TickOutcome($state, true, "Installed version $version.");
    }

    /**
     * Writes a file. $keep: a file that is there with other content is not replaced (the document root belongs to the person).
     */
    private static function write(string $path, string $content, bool $keep = false): void
    {
        if ($keep && is_file($path) && file_get_contents($path) !== $content) {
            throw new InstallException("\"$path\" appeared in the meantime and is not ours; the installer does not overwrite it.");
        }
        if (@file_put_contents($path, $content) === false) {
            throw new InstallException("Cannot write \"$path\".");
        }
        @chmod($path, 0644);
    }
}
