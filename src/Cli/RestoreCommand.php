<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

use Closure;
use JsonException;
use ThreadSafe\Core\ExitCode;
use ThreadSafe\Core\Paths;
use ThreadSafe\Core\Report;
use ThreadSafe\Core\Step;
use ThreadSafe\Crypto\CryptoException;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\EngineException;
use ThreadSafe\Engine\Executor;
use ThreadSafe\Engine\Run;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Plugin\PluginError;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Plugin\WebsiteType;
use ThreadSafe\Restore\BackupSource;
use ThreadSafe\Restore\Check;
use ThreadSafe\Restore\Inspection;
use ThreadSafe\Restore\RestoreException;
use ThreadSafe\Restore\RestoreRun;
use ThreadSafe\Storage\SpaceCheck;
use Throwable;

/**
 * `threadsafe restore <archive> --root=DIR ...`: puts a backup back into an empty directory and an empty database, and adapts
 * it to its new address (docs/PHASE3.md). Without --yes it only inspects: what the backup is, what the restore would do, and
 * every requirement it checked. With --yes it restores, in ticks that are saved, so that a run that is interrupted or fails
 * continues with `--resume=<run>`.
 */
final class RestoreCommand implements Command
{
    private ?PluginRegistry $plugins;

    /**
     * @param string|null                           $dataDirectory run states; default: the data directory of the program
     * @param (Closure(WebsiteType): list<Step>)|null $steps         the steps of the adaptation instead of those of the website type (for tests)
     */
    public function __construct(private readonly ?string $dataDirectory = null, private readonly ?Closure $steps = null, ?PluginRegistry $plugins = null)
    {
        $this->plugins = $plugins;
    }

    private function plugins(): PluginRegistry
    {
        return $this->plugins ??= PluginRegistry::load();
    }

    public function name(): string
    {
        return 'restore';
    }

    public function summary(): string
    {
        return 'Restore a backup into an empty directory and database, and adapt it (dry run unless --yes)';
    }

    public function usage(): string
    {
        return 'restore <archive> --root=DIR [--to=DOMAIN] [database options] [--yes]   |   restore --resume=RUN';
    }

    public function options(): array
    {
        $options = [
            new OptionSpec('root', 'Directory to restore into (empty or not yet there)', takesValue: true, valueName: 'DIR'),
            new OptionSpec('to', 'The new domain, e.g. shop.example', takesValue: true, valueName: 'DOMAIN'),
            new OptionSpec('from', 'The old domain (default: what the backup says)', takesValue: true, valueName: 'DOMAIN'),
            new OptionSpec('domain', 'Another mapping, old=new (repeatable)', takesValue: true, valueName: 'OLD=NEW', repeatable: true),
            new OptionSpec('db-host', 'Database host', takesValue: true, valueName: 'HOST'),
            new OptionSpec('db-port', 'Database port (default 3306)', takesValue: true, valueName: 'PORT'),
            new OptionSpec('db-name', 'Name of the empty database (it must exist)', takesValue: true, valueName: 'NAME'),
            new OptionSpec('db-user', 'Database user', takesValue: true, valueName: 'USER'),
            ...KeyOptions::specs('restore'),
            new OptionSpec('db-password-file', 'File containing the database password (or set THREADSAFE_DB_PASSWORD)', takesValue: true, valueName: 'FILE'),
            new OptionSpec('db-driver', 'PHP interface to the database: auto (default), pdo or mysqli', takesValue: true, valueName: 'DRIVER'),
            new OptionSpec('prefix', 'Table prefix (default: the one of the backup)', takesValue: true, valueName: 'PREFIX'),
            new OptionSpec('server-path', 'Directory of the site as the web server sees it, if it differs from --root', takesValue: true, valueName: 'PATH'),
            new OptionSpec('skip-tables', 'Tables the content replacement skips, comma separated', takesValue: true, valueName: 'LIST'),
            new OptionSpec('replace-bare', 'Also replace bare domain names in content (not only URLs)'),
            new OptionSpec('chunk-size', 'Rows per chunk in the content replacement (default 2000)', takesValue: true, valueName: 'N'),
            new OptionSpec('self-test-url', 'Self-test: address the web server answers on if not the site domain, e.g. http://web', takesValue: true, valueName: 'URL'),
            new OptionSpec('self-test-insecure', 'Self-test: do not verify TLS certificates'),
            new OptionSpec('no-self-test', 'Do not check the restored site over HTTP at the end'),
            new OptionSpec('type', 'Website type to restore as (default: the type of the backup)', takesValue: true, valueName: 'TYPE'),
            new OptionSpec('work-dir', 'Directory for the unpacked SQL files and the journal (default: <root>/.threadsafe-restore)', takesValue: true, valueName: 'DIR'),
            new OptionSpec('allow-leftovers', 'Let web server placeholders (.htaccess, index.html ...) in the target directory pass'),
            new OptionSpec('tick-seconds', 'Work in steps of this many seconds, saving the state in between (default: no limit)', takesValue: true, valueName: 'N'),
            new OptionSpec('resume', 'Continue the interrupted or failed run with this id', takesValue: true, valueName: 'RUN'),
            new OptionSpec('report', 'Write the result as JSON to this file', takesValue: true, valueName: 'FILE'),
            new OptionSpec('yes', 'Really restore'),
        ];
        $known = array_flip(array_map(static fn (OptionSpec $o): string => $o->name, $options));
        foreach ($this->plugins()->all() as $type) {
            foreach ($type->options() as $option) {
                if (!isset($known[$option->name])) {
                    $known[$option->name] = true;
                    $options[] = new OptionSpec($option->name, $option->description . ' [' . $type->id() . ']', $option->takesValue, $option->short, $option->valueName, $option->repeatable);
                }
            }
        }

        return $options;
    }

    public function run(Input $input, Output $output): ExitCode
    {
        $store = new RunStore(($this->dataDirectory ?? Paths::data()) . '/runs');
        $seconds = $this->tickSeconds($input);
        $budgets = static fn (): Budget => $seconds === null ? Budget::unlimited() : Budget::seconds($seconds);
        $task = new RestoreRun($this->plugins(), $this->steps);
        $executor = new Executor($store, $task, $budgets);

        try {
            if ($input->has('resume')) {
                return $this->resume((string) $input->value('resume'), $executor, $store, $input, $output);
            }

            return $this->restore($input, $output, $task, $executor, $store);
        } catch (RestoreException $e) {
            $output->error($e->getMessage());

            return $e->exitCode;
        }
    }

    private function restore(Input $input, Output $output, RestoreRun $task, Executor $executor, RunStore $store): ExitCode
    {
        $archive = $input->argument(0) ?? throw new UsageError('Say which backup to restore: restore <archive> --root=DIR. The archive is any part of the backup.');
        if (count($input->arguments()) > 1) {
            throw new UsageError('Unexpected argument "' . $input->arguments()[1] . '".');
        }
        $root = $input->value('root') ?? throw new UsageError('Say where to restore to: --root=DIR.');

        try {
            $unlock = KeyOptions::unlock($input);
        } catch (CryptoException $e) {
            throw new UsageError($e->getMessage(), 0, $e);
        }
        $source = BackupSource::open($archive, $unlock);
        $typeId = $input->value('type') ?? $source->manifest->type;
        try {
            $type = $this->plugins()->get($typeId);
        } catch (PluginError $e) {
            throw new UsageError($e->getMessage(), 0, $e);
        }

        $parameters = [
            'archive' => $source->parts[0],
            'unlock' => $unlock?->toArray(),
            'root' => self::absolute($root),
            'type' => $input->value('type'),
            'allowLeftovers' => $input->has('allow-leftovers'),
            'settings' => $this->settings($input, $type),
        ];
        if ($input->has('work-dir')) {
            $parameters['work'] = self::absolute((string) $input->value('work-dir'));
        }
        $state = $task->initialState($parameters);

        $inspection = $task->inspect($state);
        $this->describe($inspection, $state, $output);
        if (!$inspection->ok()) {
            $output->error($inspection->errorMessage());

            return ExitCode::GuardFailed;
        }
        if (!$input->has('yes')) {
            $output->writeln();
            $output->writeln('Nothing was changed. Run the same command with --yes to restore.');

            return ExitCode::Ok;
        }

        $run = $store->create($task->id(), $state);
        $output->writeln();
        $output->writeln("Run {$run->id}. If it stops, continue with: threadsafe restore --resume={$run->id}");

        return $this->execute($executor, $run->id, $store, $input, $output);
    }

    private function resume(string $id, Executor $executor, RunStore $store, Input $input, Output $output): ExitCode
    {
        try {
            $run = $store->load($id);
        } catch (EngineException $e) {
            throw new UsageError($e->getMessage(), 0, $e);
        }
        if ($run->task !== 'restore') {
            throw new UsageError("Run \"$id\" is not a restore.");
        }
        if ($run->status === RunStatus::Failed) {
            $output->writeln("Continuing run $id after the failure: " . ($run->error ?? 'unknown') . '.');
            $executor->retry($id);
        } elseif ($run->status === RunStatus::Finished) {
            throw new UsageError("Run \"$id\" is finished.");
        }

        return $this->execute($executor, $id, $store, $input, $output);
    }

    private function execute(Executor $executor, string $id, RunStore $store, Input $input, Output $output): ExitCode
    {
        $last = '';
        $onTick = static function (Run $run) use (&$last, $output): void {
            if ($run->progress !== null && $run->progress !== $last) {
                $last = $run->progress;
                $output->writeln($run->progress);
            }
        };
        try {
            $run = $executor->execute($id, null, $onTick);
        } catch (RestoreException $e) {
            $output->error($e->getMessage());
            $output->error("The state is kept as run $id. After you have removed the cause: threadsafe restore --resume=$id");

            return $e->exitCode;
        } catch (Throwable $e) {
            $output->error('Restore failed: ' . $e->getMessage());
            $output->error("The state is kept as run $id. After you have removed the cause: threadsafe restore --resume=$id");

            return ExitCode::StepFailed;
        }

        return $this->finished($run, $store, $input, $output);
    }

    private function finished(Run $run, RunStore $store, Input $input, Output $output): ExitCode
    {
        $result = (array) ($run->state['result'] ?? []);
        $output->writeln();
        $output->writeln(sprintf(
            'Restored %s into %s: %d files, %d directories, %s%s.',
            (string) ($result['backup'] ?? 'the backup'),
            (string) ($result['root'] ?? ''),
            (int) ($result['files'] ?? 0),
            (int) ($result['directories'] ?? 0),
            SpaceCheck::format((int) ($result['bytes'] ?? 0)),
            (int) ($result['statements'] ?? 0) > 0 ? sprintf(', %d SQL statements', (int) $result['statements']) : '',
        ));
        foreach ((array) ($result['warnings'] ?? []) as $warning) {
            $output->writeln('Warning: ' . $warning);
        }
        if (is_array($result['report'] ?? null)) {
            $output->writeln();
            (new TextReportRenderer())->render(Report::fromArray($result['report']), $output);
        }
        $this->writeReport($input->value('report'), $result);
        $store->delete($run->id);

        return ExitCode::Ok;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function describe(Inspection $inspection, array $state, Output $output): void
    {
        $manifest = $inspection->source->manifest;
        $version = $manifest->application['version'];
        $output->writeln(sprintf(
            'Backup %s: %s%s, %d part(s), made %s UTC.',
            $manifest->backupId,
            $manifest->application['name'],
            $version === null ? '' : ' ' . $version,
            count($inspection->source->parts),
            $manifest->finished->format('Y-m-d H:i'),
        ));
        $output->writeln(sprintf(
            'Contents: %d files in %d directories (%s)%s.',
            $manifest->counts['files'],
            $manifest->counts['directories'],
            SpaceCheck::format($manifest->counts['fileBytes']),
            $manifest->database === [] ? ', no database' : sprintf(', %d tables (%s of SQL)', $manifest->counts['tables'], SpaceCheck::format($manifest->counts['databaseBytes'])),
        ));
        $output->writeln('Restore into: ' . $state['root']);
        if ($inspection->database !== null) {
            $output->writeln(sprintf('Database: %s on %s, prefix "%s".', $inspection->database->name, $inspection->database->host, $inspection->database->prefix));
        }
        if (isset($inspection->settings['from'], $inspection->settings['to'])) {
            $output->writeln(sprintf('Domain: %s -> %s', $inspection->settings['from'], $inspection->settings['to']));
        }
        foreach ($inspection->checks as $check) {
            $output->writeln(match ($check->level) {
                Check::ERROR => 'ERROR:   ' . $check->message . ($check->hint !== '' ? ' ' . $check->hint : ''),
                Check::WARNING => 'Warning: ' . $check->message,
                default => 'Note:    ' . $check->message,
            });
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(Input $input, WebsiteType $type): array
    {
        $settings = [];
        foreach (['from' => 'from', 'to' => 'to', 'server-path' => 'serverPath', 'self-test-url' => 'selfTestUrl'] as $option => $key) {
            if ($input->has($option)) {
                $settings[$key] = $input->value($option);
            }
        }
        if ($input->has('domain')) {
            $settings['domains'] = $input->values('domain');
        }

        $database = [];
        foreach (['db-host' => 'host', 'db-port' => 'port', 'db-name' => 'name', 'db-user' => 'user', 'db-password-file' => 'passwordFile', 'db-driver' => 'driver', 'prefix' => 'prefix'] as $option => $key) {
            if ($input->has($option)) {
                $database[$key] = $input->value($option);
            }
        }
        if ($database !== []) {
            if (!isset($database['passwordFile']) && ($password = getenv('THREADSAFE_DB_PASSWORD')) !== false && $password !== '') {
                $database['password'] = $password;
            }
            $settings['database'] = $database;
        }

        if ($input->has('skip-tables')) {
            $settings['skipTables'] = array_values(array_filter(array_map('trim', explode(',', (string) $input->value('skip-tables')))));
        }
        if ($input->has('no-self-test')) {
            $settings['selfTest'] = false;
        }
        if ($input->has('self-test-insecure')) {
            $settings['selfTestInsecure'] = true;
        }
        if ($input->has('replace-bare')) {
            $settings['replaceBare'] = true;
        }
        if ($input->has('chunk-size')) {
            $settings['chunkSize'] = $input->value('chunk-size');
        }

        return [...$settings, ...$type->settings($input)];
    }

    private function tickSeconds(Input $input): ?float
    {
        if (!$input->has('tick-seconds')) {
            return null;
        }
        $seconds = $input->value('tick-seconds');
        if ($seconds === null || !is_numeric($seconds) || (float) $seconds < 0) {
            throw new UsageError('--tick-seconds is a number of seconds.');
        }

        return (float) $seconds;
    }

    private static function absolute(string $path): string
    {
        return preg_match('~^(/|[A-Za-z]:[\\\\/])~', $path) === 1 ? $path : rtrim((string) getcwd(), '/\\') . '/' . $path;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function writeReport(?string $path, array $result): void
    {
        if ($path === null) {
            return;
        }
        try {
            $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return;
        }
        if (@file_put_contents($path, $json . "\n") === false) {
            throw new UsageError("The report file \"$path\" cannot be written.");
        }
        @chmod($path, 0600);
    }
}
