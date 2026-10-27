<?php

declare(strict_types=1);

namespace ThreadSafe\Update;

use Closure;
use ThreadSafe\Core\Environment;
use ThreadSafe\Core\Home;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\Lock;
use ThreadSafe\Engine\Task;
use ThreadSafe\Engine\TickOutcome;
use ThreadSafe\Install\InstallException;
use ThreadSafe\Install\InstallPackage;
use ThreadSafe\Install\Unpacker;
use ThreadSafe\Profile\CheckLevel;

/**
 * The update from a package as a task in ticks (docs/PHASE7.md 3.5):
 *
 *   1. **verify:** the SHA-256 of the file is the one the person typed; the manifest is read; the version is newer (or an older one was
 *      asked for); the data is not newer than the package writes; this host meets its requirements. Nothing has changed yet.
 *   2. **extract:** the package into `releases/<version>.partial/`, every file checked against the manifest ({@see Unpacker}). The running
 *      program is not touched.
 *   3. **switch:** nothing may be running; a copy of the state files; the release gets its name; the pointer is written (the old release
 *      becomes `previous`); the health check asks the new version to answer. If it does not, **the pointer goes back and the copy is put
 *      back**, and the run finishes with `rolledBack` in its result. If it does, the old releases beyond the previous one are removed.
 *
 * A failure in 1 to 3 before the pointer is written leaves the installation as it was (the run fails with the message; a half-unpacked
 * directory is removed by the next start). No code of the package runs before the checks of 1 and 2 have passed.
 */
final class UpdateRun implements Task
{
    /**
     * @param (Closure(string, string, string): HealthResult)|null $health after the switch: (home, data, version) => the answer of the new version; null = not checked
     * @param (Closure(): int)|null                                $clock  for tests: the time in seconds
     */
    public function __construct(private readonly ?Closure $health = null, private readonly ?Closure $clock = null)
    {
    }

    public function id(): string
    {
        return 'update';
    }

    /**
     * @param array<string, mixed> $parameters `file` (the package), `sha256` (what the person typed), `home`, `data`, `allowDowngrade`, `skipHealthCheck`
     *
     * @return array<string, mixed>
     */
    public function initialState(array $parameters): array
    {
        return [
            'phase' => 'verify',
            'file' => (string) ($parameters['file'] ?? ''),
            'sha256' => strtolower(trim((string) ($parameters['sha256'] ?? ''))),
            'home' => rtrim(str_replace('\\', '/', (string) ($parameters['home'] ?? '')), '/'),
            'data' => rtrim(str_replace('\\', '/', (string) ($parameters['data'] ?? '')), '/'),
            'allowDowngrade' => (bool) ($parameters['allowDowngrade'] ?? false),
            'skipHealthCheck' => (bool) ($parameters['skipHealthCheck'] ?? false),
            'from' => '',
            'version' => '',
            'entry' => 0,
            'position' => 0,
            'files' => 0,
            'bytes' => 0,
            'expected' => 0,
            'result' => null,
        ];
    }

    public function tick(array $state, Budget $budget): TickOutcome
    {
        return match ($state['phase'] ?? '') {
            'verify' => $this->verify($state),
            'extract' => $this->extract($state, $budget),
            'switch' => $this->switch($state),
            default => new TickOutcome($state, true, 'Done.'),
        };
    }

    // ------------------------------------------------------------------------------------------------- verify

    /**
     * @param array<string, mixed> $state
     */
    private function verify(array $state): TickOutcome
    {
        $file = (string) $state['file'];
        $home = (string) $state['home'];
        $data = (string) $state['data'];
        if (!is_file($file)) {
            throw new UpdateException('The package is not there any more; upload it again.');
        }
        if (preg_match('/^[0-9a-f]{64}$/', (string) $state['sha256']) !== 1) {
            throw new UpdateException('The checksum must be the 64 hexadecimal characters that stand next to the file on the release page (SHA256SUMS).');
        }
        $actual = (string) hash_file('sha256', $file);
        if (!hash_equals((string) $state['sha256'], $actual)) {
            throw new UpdateException("The checksum of the file is $actual, not the one you gave. The file did not arrive whole, or it is not the file of that release page; nothing was changed.");
        }

        try {
            $package = InstallPackage::open($file);
        } catch (InstallException $e) {
            throw new UpdateException($e->getMessage(), 0, $e);
        }
        $manifest = $package->manifest;
        $pointer = Home::readPointer($home) ?? throw new UpdateException('The pointer to the active release (current.php) is missing or damaged; an update needs a working installation.');
        $installed = $pointer['release'];
        $order = version_compare($manifest->version, $installed);
        if ($order === 0) {
            throw new UpdateException("Version $installed is installed already.");
        }
        if ($order < 0 && !(bool) $state['allowDowngrade']) {
            throw new UpdateException("Version {$manifest->version} is older than the installed $installed. Say that you want an older version if you mean it.");
        }
        $stored = DataFormat::read($data)['dataFormat'] ?? 1;
        if ($manifest->dataFormat < $stored) {
            throw new UpdateException("The data of this installation has format $stored; version {$manifest->version} writes format {$manifest->dataFormat} and cannot read it. Use a newer package.");
        }
        $problems = array_map(
            static fn ($check): string => $check->message,
            array_filter(Environment::requirements($manifest->version, $manifest->php, $manifest->extensions), static fn ($check): bool => $check->level === CheckLevel::Failure),
        );
        if ($problems !== []) {
            throw new UpdateException('This host cannot run version ' . $manifest->version . ': ' . implode(' ', $problems) . ' Nothing was changed.');
        }
        if (!is_writable($home) || !is_writable($home . '/releases')) {
            throw new UpdateException("PHP cannot write in \"$home\"; the web server's user must be able to write there for an update.");
        }
        if (($busy = Busy::reason($data, $this->clock)) !== null) {
            throw new UpdateException($busy);
        }

        // leftovers of an earlier try: half-unpacked directories, and a release of this name that is not the active one
        foreach (Releases::all($home) as $name) {
            if (str_ends_with($name, '.partial') || ($name === $manifest->version && $name !== $installed)) {
                Releases::remove($home, $name);
            }
        }
        $partial = $home . '/releases/' . $manifest->version . '.partial';
        if (!@mkdir($partial, 0755) && !is_dir($partial)) {
            throw new UpdateException("Cannot create the directory \"$partial\".");
        }

        $state = [...$state, ...Unpacker::initialState($package), 'phase' => 'extract', 'from' => $installed, 'version' => $manifest->version];

        return new TickOutcome($state, false, "Version {$manifest->version} checks out: checksum, manifest, requirements.");
    }

    // ------------------------------------------------------------------------------------------------- extract

    /**
     * @param array<string, mixed> $state
     */
    private function extract(array $state, Budget $budget): TickOutcome
    {
        try {
            [$state, $complete] = (new Unpacker())->unpack($state, (string) $state['file'], $state['home'] . '/releases/' . $state['version'] . '.partial', $budget);
        } catch (InstallException $e) {
            Releases::remove((string) $state['home'], $state['version'] . '.partial');

            throw new UpdateException($e->getMessage(), 0, $e);
        }
        if (!$complete) {
            return new TickOutcome($state, false, sprintf('Unpacking: %d of %d files', $state['files'], $state['expected']));
        }
        if (!is_file($state['home'] . '/releases/' . $state['version'] . '.partial/vendor/autoload.php')) {
            Releases::remove((string) $state['home'], $state['version'] . '.partial');

            throw new UpdateException('The package has no vendor/autoload.php; it cannot run. Nothing was changed.');
        }
        $state['phase'] = 'switch';

        return new TickOutcome($state, false, sprintf('Unpacked %d files.', $state['files']));
    }

    // ------------------------------------------------------------------------------------------------- switch

    /**
     * @param array<string, mixed> $state
     */
    private function switch(array $state): TickOutcome
    {
        $home = (string) $state['home'];
        $data = (string) $state['data'];
        $from = (string) $state['from'];
        $to = (string) $state['version'];

        if (($busy = Busy::reason($data, $this->clock)) !== null) {
            throw new UpdateException($busy . ' The package is unpacked; continue when it has finished.');
        }
        $lock = Lock::tryAcquire($data . '/locks/update.lock', 'update to ' . $to . ', pid ' . getmypid());
        if ($lock === null) {
            throw new UpdateException('Another update is working (' . (Lock::holder($data . '/locks/update.lock') ?? 'holder unknown') . ').');
        }
        try {
            $pointer = Home::readPointer($home) ?? throw new UpdateException('The pointer to the active release is missing or damaged.');
            $now = $this->clock !== null ? ($this->clock)() : time();
            $copy = StateBackup::make($data, $from . '-' . gmdate('Ymd-His', $now));

            $partial = Home::releaseDirectory($home, $to) . '.partial';
            if (is_dir($partial) && !@rename($partial, Home::releaseDirectory($home, $to))) {
                throw new UpdateException("Cannot rename \"$partial\".");
            }
            Home::writePointer($home, $to, $from);

            $result = match (true) {
                (bool) ($state['skipHealthCheck'] ?? false) => HealthResult::skipped('The new version was not started to check it, as you asked.'),
                $this->health !== null => ($this->health)($home, $data, $to),
                default => HealthResult::skipped('The new version was not started to check it.'),
            };
            if ($result->status === HealthResult::FAILED) {
                // the switch is taken back: the pointer, the state files, and the release that did not start
                Home::writePointer($home, $from, $pointer['previous']);
                StateBackup::restore($data, $copy);
                Releases::remove($home, $to);
                $state['result'] = ['rolledBack' => true, 'from' => $from, 'to' => $to, 'health' => $result->status, 'message' => $result->message, 'copy' => $copy];
                $state['phase'] = 'done';

                return new TickOutcome($state, true, "Version $to did not start; version $from is active again.");
            }

            $problems = Releases::prune($home, [$to, $from]);
            @unlink((string) $state['file']);
            $state['result'] = ['rolledBack' => false, 'from' => $from, 'to' => $to, 'health' => $result->status, 'message' => $result->message, 'copy' => $copy, 'problems' => $problems];
            $state['phase'] = 'done';
        } finally {
            $lock->release();
        }

        return new TickOutcome($state, true, "Updated from $from to $to.");
    }
}
