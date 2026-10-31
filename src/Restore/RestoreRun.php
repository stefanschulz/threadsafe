<?php

declare(strict_types=1);

namespace ThreadSafe\Restore;

use Closure;
use InvalidArgumentException;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\ExitCode;
use ThreadSafe\Core\FileJournal;
use ThreadSafe\Core\NullLogger;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\TickedRunner;
use ThreadSafe\Crypto\CryptoException;
use ThreadSafe\Crypto\Unlock;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\ConnectionFailed;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\Task;
use ThreadSafe\Engine\TickOutcome;
use ThreadSafe\Plugin\PluginError;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Plugin\Restorable;
use ThreadSafe\Plugin\WebsiteType;
use ThreadSafe\Web\StateDirectory;

/**
 * Puts a backup back (docs/PHASE3.md 3.3): **preflight** (everything that can be checked before a byte is written), **extract**,
 * **import**, **adapt** (the migration of phase 1, in ticks) and **finalize** (the SQL files go, the secrets are scrubbed from the state).
 *
 * It restores onto an empty target and never deletes or overwrites an installation (3.8). A failure stops the run in the phase where it
 * happened; running the same command again continues from the last finished tick (a file that was being extracted or imported starts again
 * from its beginning where it cannot continue in the middle).
 *
 * Parameters of {@see initialState()}: `archive` (any part of the backup), `root`, `settings` (the request settings of the website type,
 * see {@see \ThreadSafe\Migration\RequestBuilder}, without `root`; `database` holds the target database, password included), `type`
 * (overrides the type of the backup), `allowLeftovers`, `dropTables` (delete the tables with the target's prefix before the import instead
 * of refusing a database that has some), `work` (default `<root>/.threadsafe-restore`), `unlock` ({@see Unlock::toArray()}),
 * `journalDirectory` (default `<work>/journal`), `protected` (paths below the root that are never written: by default the tool's own files
 * and the work directory), `guard` (the tool's files that the `.htaccess` of the restored site must not lock out, {@see ToolGuard}).
 *
 * The state holds the secret key or passphrase of an encrypted backup until the extraction is done, and the database password until the
 * last tick, which blanks it; the file that {@see \ThreadSafe\Engine\RunStore} writes is private (mode 0600) and the caller deletes it when
 * the run is finished.
 */
final class RestoreRun implements Task
{
    private const array PHASES = ['preflight' => 1, 'extract' => 2, 'import' => 3, 'adapt' => 4, 'finalize' => 5];

    /**
     * @param (Closure(WebsiteType): list<Step>)|null $steps the steps of the adaptation (default: those of the website type); for tests
     */
    public function __construct(private readonly PluginRegistry $plugins, private readonly ?Closure $steps = null)
    {
    }

    public function id(): string
    {
        return 'restore';
    }

    public function initialState(array $parameters): array
    {
        $archive = (string) ($parameters['archive'] ?? '');
        $root = rtrim((string) ($parameters['root'] ?? ''), '/\\');
        if ($archive === '') {
            throw new RestoreException('Say which backup to restore (archive).', ExitCode::Usage);
        }
        if ($root === '' || preg_match('~^(/|[A-Za-z]:[\\\\/])~', $root) !== 1) {
            throw new RestoreException('The target directory (root) must be an absolute path.', ExitCode::Usage);
        }
        $settings = (array) ($parameters['settings'] ?? []);
        if (isset($settings['root'])) {
            throw new RestoreException('The target directory is not a setting; it is the parameter "root".', ExitCode::Usage);
        }
        if (isset($settings['database']) && is_array($settings['database'])) {
            $settings['database'] = self::resolvePassword($settings['database']);
        }
        try {
            $unlock = Unlock::fromArray($parameters['unlock'] ?? null);
        } catch (CryptoException $e) {
            throw new RestoreException($e->getMessage(), ExitCode::Usage, $e);
        }
        $work = rtrim((string) ($parameters['work'] ?? $root . '/.threadsafe-restore'), '/\\');

        return [
            'phase' => 'preflight',
            'archive' => $archive,
            'unlock' => $unlock?->toArray(),
            'root' => $root,
            'work' => $work,
            'journalDirectory' => rtrim((string) ($parameters['journalDirectory'] ?? $work . '/journal'), '/\\'),
            'settings' => $settings,
            'type' => isset($parameters['type']) ? (string) $parameters['type'] : null,
            'allowLeftovers' => (bool) ($parameters['allowLeftovers'] ?? false),
            'dropTables' => (bool) ($parameters['dropTables'] ?? false),
            'cleared' => false,
            'protected' => array_values(array_map('strval', (array) ($parameters['protected'] ?? ['threadsafe-restore.php', 'threadsafe-restore-key.php']))),
            'guard' => array_values(array_map('strval', (array) ($parameters['guard'] ?? []))),
            'parts' => [],
            'manifest' => null,
            'checks' => [],
            'warnings' => [],
            'extract' => null,
            'import' => null,
            'adapt' => null,
            'adaptReport' => null,
            'result' => null,
        ];
    }

    /**
     * Everything the restore can find out without writing: the backup, the requirements, the settings as they will be used. This
     * is the dry run of the command, and the first tick of the run.
     *
     * @param array<string, mixed> $state as made by {@see initialState()}
     *
     * @throws RestoreException the backup cannot be found or read
     */
    public function inspect(array $state): Inspection
    {
        try {
            $unlock = Unlock::fromArray($state['unlock'] ?? null);
        } catch (CryptoException $e) {
            throw new RestoreException($e->getMessage(), ExitCode::GuardFailed, $e);
        }
        $source = BackupSource::open((string) $state['archive'], $unlock);
        $manifest = $source->manifest;
        $typeId = is_string($state['type'] ?? null) ? $state['type'] : $manifest->type;
        try {
            $type = $this->plugins->get($typeId);
        } catch (PluginError $e) {
            throw new RestoreException("The backup is of the website type \"$typeId\"; this program has no such type: " . $e->getMessage(), ExitCode::GuardFailed, $e);
        }
        if (!$type instanceof Restorable) {
            throw new RestoreException("The website type \"$typeId\" cannot be restored.", ExitCode::GuardFailed);
        }

        $settings = (array) $state['settings'];
        $database = null;
        if (isset($settings['database']) && is_array($settings['database'])) {
            $given = $settings['database'];
            $given['prefix'] ??= (string) ($manifest->database['prefix'] ?? '');
            $given['port'] ??= 3306;
            $database = self::databaseSettings($given);
            $settings['database'] = $given;
            if ($manifest->database === []) {
                unset($settings['database']);      // a site without a database does not use the settings (the preflight says so)
            }
        }
        if (isset($settings['to']) && !isset($settings['from']) && ($domain = $type->sourceDomain($manifest)) !== null) {
            $settings['from'] = $domain;
        }

        $root = (string) $state['root'];
        $protected = array_values(array_unique([...(array) $state['protected'], ...$this->workProtection($root, (string) $state['work'])]));
        $own = $protected;
        foreach ($source->parts as $part) {
            if (dirname($part) === $root) {
                $own[] = basename($part);
            }
        }
        $sidecar = $manifest->name()?->sidecarFile();
        if ($sidecar !== null && is_file($root . '/' . $sidecar)) {
            $own[] = $sidecar;
        }
        $own = array_map(static fn (string $p): string => explode('/', $p)[0], $own);

        $checks = (new Preflight())->run($source, $root, $database, (bool) $state['allowLeftovers'], $own, $type, (bool) ($state['dropTables'] ?? false));
        // the settings are checked on a directory that exists; nothing is read from it
        try {
            $type->requestBuilder()->build(['root' => sys_get_temp_dir()] + $settings);
        } catch (InvalidArgumentException | GuardFailed $e) {
            $checks[] = Check::error($e->getMessage());
        }

        return new Inspection($source, $typeId, $settings, $checks, $database, $protected);
    }

    public function tick(array $state, Budget $budget): TickOutcome
    {
        return match ($state['phase']) {
            'preflight' => $this->preflight($state),
            'extract' => $this->extract($state, $budget),
            'import' => $this->import($state, $budget),
            'adapt' => $this->adapt($state, $budget),
            'finalize' => $this->finalize($state),
            default => new TickOutcome($state, true, 'restored'),
        };
    }

    // ------------------------------------------------------------------------------------------------- phases

    /**
     * @param array<string, mixed> $state
     */
    private function preflight(array $state): TickOutcome
    {
        $inspection = $this->inspect($state);
        if (!$inspection->ok()) {
            throw new RestoreException($inspection->errorMessage(), ExitCode::GuardFailed);
        }
        $manifest = $inspection->source->manifest;
        $root = (string) $state['root'];
        if (!is_dir($root) && !@mkdir($root, 0755, true) && !is_dir($root)) {
            throw new RestoreException("Cannot create the directory \"$root\".", ExitCode::StepFailed);
        }
        $work = (string) $state['work'];
        try {
            // inside the web root (the default) it must not be served: the journal in it holds old and new values, passwords included
            StateDirectory::prepare($work);
        } catch (\RuntimeException $e) {
            throw new RestoreException($e->getMessage(), ExitCode::StepFailed, $e);
        }

        try {
            $extract = (new Extractor())->initialState([
                'parts' => $inspection->source->parts,
                'unlock' => $state['unlock'] ?? null,
                'root' => $state['root'],
                'database' => $work . '/db',
                'protected' => $inspection->protected,
                'guard' => $state['guard'] ?? [],
            ]);
        } catch (ExtractException $e) {
            throw new RestoreException($e->getMessage(), ExitCode::StepFailed, $e);
        }

        foreach ($inspection->checks as $check) {
            if ($check->level === Check::WARNING) {
                $state['warnings'][] = $check->message;
            }
        }
        $state['parts'] = $inspection->source->parts;
        $state['manifest'] = $manifest->toArray();
        $state['type'] = $inspection->typeId;
        $state['settings'] = $inspection->settings;
        $state['protected'] = $inspection->protected;
        $state['checks'] = array_map(static fn (Check $c): array => $c->toArray(), $inspection->checks);
        $state['extract'] = $extract;
        $state['phase'] = 'extract';

        return new TickOutcome($state, false, $this->progress('preflight', sprintf('backup %s, %d part(s) - requirements are met', $manifest->backupId, count($state['parts']))));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function extract(array $state, Budget $budget): TickOutcome
    {
        try {
            $outcome = (new Extractor())->tick((array) $state['extract'], $budget);
        } catch (ExtractException $e) {
            throw new RestoreException($e->getMessage(), ExitCode::StepFailed, $e);
        }
        $state['extract'] = $outcome->state;
        if (!$outcome->finished) {
            return new TickOutcome($state, false, $this->progress('extract', (string) $outcome->progress));
        }
        foreach ((array) $outcome->state['warnings'] as $warning) {
            $state['warnings'][] = 'Extraction: ' . $warning;
        }
        // the archive is read; nothing after this needs the key, so the state does not keep it
        $state['unlock'] = null;
        $state['extract']['unlock'] = null;

        $manifest = $state['manifest'];
        $hasDump = (int) ($manifest['counts']['tables'] ?? 0) > 0 && is_dir($state['work'] . '/db');
        if ($hasDump) {
            $database = self::databaseSettings((array) $state['settings']['database']);
            $sourcePrefix = (string) ($manifest['database']['prefix'] ?? '');
            try {
                $state['import'] = (new Importer($this->connection($database)))->initialState([
                    'directory' => $state['work'] . '/db',
                    'prefixFrom' => $sourcePrefix,
                    'prefixTo' => $database->prefix,
                ]);
            } catch (ImportException $e) {
                throw new RestoreException($e->getMessage() . ' The files are restored; the database is untouched.', ExitCode::StepFailed, $e);
            }
        }
        $state['phase'] = $hasDump ? 'import' : 'adapt';

        return new TickOutcome($state, false, $this->progress('extract', (string) $outcome->progress));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function import(array $state, Budget $budget): TickOutcome
    {
        $database = self::databaseSettings((array) $state['settings']['database']);
        if ((bool) ($state['dropTables'] ?? false) && !(bool) ($state['cleared'] ?? false)) {
            return $this->clear($state, $database, $budget);
        }
        try {
            $outcome = (new Importer($this->connection($database)))->tick((array) $state['import'], $budget);
        } catch (ImportException $e) {
            throw new RestoreException($e->getMessage() . ' The database is in a partial state; run the same command again to continue.', ExitCode::StepFailed, $e);
        }
        $state['import'] = $outcome->state;
        if (!$outcome->finished) {
            return new TickOutcome($state, false, $this->progress('import', (string) $outcome->progress));
        }
        $this->removeDump((string) $state['work']);
        $state['phase'] = 'adapt';

        return new TickOutcome($state, false, $this->progress('import', (string) $outcome->progress));
    }

    /**
     * The tables of the target's prefix are deleted, after the files are unpacked (a restore that fails early leaves the database alone) and before
     * the first statement of the import. Only asked for by the person; the preflight named what goes.
     *
     * @param array<string, mixed> $state
     */
    private function clear(array $state, DatabaseSettings $database, Budget $budget): TickOutcome
    {
        $db = $this->connection($database);
        try {
            $left = TableDropper::drop($db, $database->prefix, $budget);
        } catch (\ThreadSafe\Database\DatabaseError $e) {
            throw new RestoreException('Cannot delete the existing tables: ' . $e->getMessage() . ' The files are restored; run the same command again to continue.', ExitCode::StepFailed, $e);
        }
        $state['cleared'] = $left === 0;

        return new TickOutcome($state, false, $this->progress('import', $left === 0 ? 'the existing tables are deleted' : sprintf('deleting the existing tables (%d left)', $left)));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function adapt(array $state, Budget $budget): TickOutcome
    {
        $type = $this->plugins->get((string) $state['type']);
        try {
            $builder = $type->requestBuilder();
            $request = $builder->build(['root' => $state['root']] + (array) $state['settings']);
        } catch (InvalidArgumentException | GuardFailed $e) {
            throw new RestoreException('The files and the database are restored, but the adaptation cannot start: ' . $e->getMessage(), ExitCode::StepFailed, $e);
        }
        if ($state['adapt'] === null) {
            foreach ($builder->notes() as $note) {
                $state['warnings'][] = $note;
            }
        }

        $runner = new TickedRunner($this->steps !== null ? ($this->steps)($type) : $type->steps());
        $adapt = is_array($state['adapt']) ? $state['adapt'] : $runner->initialState();
        $journal = new FileJournal((string) $state['journalDirectory'], null, is_string($adapt['journal']) ? $adapt['journal'] : null);
        try {
            $outcome = $runner->tick($adapt, new Context($request, $journal, new NullLogger(), false), $budget);
        } finally {
            $journal->close();
            // a step may have written the .htaccess anew: the tool stays reachable for the next request
            ToolGuard::protect((string) $state['root'], self::guard($state));
        }
        $state['adapt'] = $outcome->state;
        if (!$outcome->finished) {
            return new TickOutcome($state, false, $this->progress('adapt', (string) $outcome->progress));
        }

        $report = $runner->report($outcome->state);
        $state['adaptReport'] = $report->toArray();
        if (is_array($outcome->state['failure'])) {
            throw new RestoreException(
                'The files and the database are restored, but the adaptation failed: ' . $outcome->state['failure']['message'] . ' The journal is ' . ($outcome->state['journal'] ?? 'not written') . '.',
                $runner->exitCode($outcome->state),
            );
        }
        $state['adapt'] = null;
        $state['phase'] = 'finalize';

        return new TickOutcome($state, false, $this->progress('adapt', (string) $outcome->progress));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function finalize(array $state): TickOutcome
    {
        $this->removeDump((string) $state['work']);
        $extract = (array) $state['extract'];
        $import = (array) ($state['import'] ?? []);
        $manifest = (array) $state['manifest'];

        $state['result'] = [
            'backup' => $manifest['backupId'] ?? null,
            'type' => $state['type'],
            'root' => $state['root'],
            'files' => $extract['files'] ?? 0,
            'databaseFiles' => $extract['databaseFiles'] ?? 0,
            'directories' => $extract['directories'] ?? 0,
            'bytes' => $extract['bytes'] ?? 0,
            'statements' => $import['statements'] ?? 0,
            'importRestarts' => $import['restarts'] ?? 0,
            'warnings' => $state['warnings'],
            'report' => $state['adaptReport'],
        ];
        if (isset($state['settings']['database']) && is_array($state['settings']['database'])) {
            $state['settings']['database']['password'] = '';
        }
        $state['phase'] = 'done';
        ToolGuard::protect((string) $state['root'], self::guard($state));

        return new TickOutcome($state, true, $this->progress('finalize', 'restored ' . ($manifest['backupId'] ?? 'the backup')));
    }

    // ------------------------------------------------------------------------------------------------- helpers

    /**
     * The files of the tool that the .htaccess of the restored site must not lock out.
     *
     * @param array<string, mixed> $state
     *
     * @return list<string>
     */
    private static function guard(array $state): array
    {
        return array_values(array_map('strval', (array) ($state['guard'] ?? [])));
    }

    private function progress(string $phase, string $detail): string
    {
        return sprintf('[%d/%d %s] %s', self::PHASES[$phase], count(self::PHASES), $phase, $detail);
    }

    /**
     * @throws RestoreException
     */
    private function connection(DatabaseSettings $settings): Connection
    {
        try {
            return Connection::open($settings);
        } catch (ConnectionFailed $e) {
            throw new RestoreException($e->getMessage(), ExitCode::StepFailed, $e);
        }
    }

    /**
     * @param array<array-key, mixed> $given
     */
    private static function databaseSettings(array $given): DatabaseSettings
    {
        return DatabaseSettings::fromArray([
            'host' => $given['host'] ?? '',
            'port' => $given['port'] ?? 3306,
            'name' => $given['name'] ?? '',
            'user' => $given['user'] ?? '',
            'password' => $given['password'] ?? '',
            'prefix' => $given['prefix'] ?? '',
            'driver' => $given['driver'] ?? DatabaseSettings::AUTO,
        ]);
    }

    /**
     * A password file is read once, here: the state then holds the password, not the name of a file that may be gone later.
     *
     * @param array<array-key, mixed> $database
     *
     * @return array<array-key, mixed>
     */
    private static function resolvePassword(array $database): array
    {
        if (isset($database['passwordFile']) && !isset($database['password'])) {
            $content = is_string($database['passwordFile']) && is_file($database['passwordFile']) ? file_get_contents($database['passwordFile']) : false;
            if ($content === false) {
                throw new RestoreException('The database password file cannot be read.', ExitCode::Usage);
            }
            $database['password'] = rtrim($content, "\r\n");
        }
        unset($database['passwordFile']);

        return $database;
    }

    /**
     * The path of the work directory below the root, as the first thing the extraction must never touch.
     *
     * @return list<string>
     */
    private function workProtection(string $root, string $work): array
    {
        return str_starts_with($work, $root . '/') ? [substr($work, strlen($root) + 1)] : [];
    }

    /** The unpacked SQL files hold a copy of the customer data: they go as soon as they have been imported. */
    private function removeDump(string $work): void
    {
        $directory = $work . '/db';
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                @unlink($directory . '/' . $name);
            }
        }
        @rmdir($directory);
    }
}
