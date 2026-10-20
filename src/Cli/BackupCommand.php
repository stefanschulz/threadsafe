<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

use JsonException;
use ThreadSafe\Backup\BackupRun;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\Exception\StepFailed;
use ThreadSafe\Core\ExitCode;
use ThreadSafe\Core\Paths;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\EngineException;
use ThreadSafe\Engine\Executor;
use ThreadSafe\Engine\Lock;
use ThreadSafe\Engine\Run;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Storage\SpaceCheck;
use Throwable;

/**
 * `threadsafe backup <profile>`, `backup --all`, `backup --resume=<run>`: backs up the sites of the profiles into their
 * storage directories. On the command line there is no time limit, so one tick does the whole backup and the database is
 * dumped inside one consistent snapshot; `--tick-seconds` cuts the work into ticks (which is how a web or cron run works, and
 * how the resume path is exercised).
 *
 * One backup runs at a time (a lock in the data directory), and one per profile. A run that fails is cleaned up: its
 * unfinished files are removed and its state is kept for diagnosis. A run whose process died is continued with --resume.
 */
final class BackupCommand implements Command
{
    private ?PluginRegistry $plugins;

    /**
     * @param string|null         $dataDirectory profiles, runs and locks; default: the data directory of the program
     * @param PluginRegistry|null $plugins       the website types; default: the plugins of the program
     */
    public function __construct(private readonly ?string $dataDirectory = null, ?PluginRegistry $plugins = null)
    {
        $this->plugins = $plugins;
    }

    public function name(): string
    {
        return 'backup';
    }

    public function summary(): string
    {
        return 'Back up the site of a profile (or all profiles), or continue an interrupted backup';
    }

    public function usage(): string
    {
        return 'backup <profile> [options] | backup --all [options] | backup --resume=RUN';
    }

    public function options(): array
    {
        return [
            new OptionSpec('all', 'Back up every profile, one after the other'),
            new OptionSpec('resume', 'Continue the interrupted run with this id', takesValue: true, valueName: 'RUN'),
            new OptionSpec('part-size', 'Split the archive into parts of this size, e.g. 2G (default: the profile\'s setting)', takesValue: true, valueName: 'SIZE'),
            new OptionSpec('compression', 'Deflate level 0 (store) to 9 (default: the profile\'s setting)', takesValue: true, valueName: 'LEVEL'),
            new OptionSpec('tick-seconds', 'Work in steps of this many seconds, saving the state in between (default: no limit)', takesValue: true, valueName: 'N'),
            new OptionSpec('force', 'Go on although the storage seems to have too little room'),
            new OptionSpec('report', 'Write the result as JSON to this file', takesValue: true, valueName: 'FILE'),
        ];
    }

    public function run(Input $input, Output $output): ExitCode
    {
        $data = $this->dataDirectory ?? Paths::data();
        $profiles = new ProfileStore($data . '/profiles');
        $runs = new RunStore($data . '/runs');
        $this->plugins ??= PluginRegistry::load();

        $mode = (int) $input->has('all') + (int) $input->has('resume') + (int) ($input->argument(0) !== null);
        if ($mode !== 1) {
            throw new UsageError('Say what to back up: a profile id, --all, or --resume=RUN.');
        }
        if (count($input->arguments()) > 1) {
            throw new UsageError('Unexpected argument "' . $input->arguments()[1] . '".');
        }
        $options = $this->parseOptions($input);

        $global = Lock::tryAcquire($data . '/locks/backup.lock', 'backup of ' . ($input->argument(0) ?? 'all profiles'));
        if ($global === null) {
            throw new GuardFailed('Another backup is running (' . (Lock::holder($data . '/locks/backup.lock') ?? 'holder unknown') . '). One backup at a time.');
        }

        try {
            if ($input->has('resume')) {
                return $this->resume((string) $input->value('resume'), $profiles, $runs, $options, $output);
            }
            if ($input->has('all')) {
                return $this->all($profiles, $runs, $options, $output, $data);
            }

            return $this->one((string) $input->argument(0), $profiles, $runs, $options, $output, $data);
        } finally {
            $global->release();
        }
    }

    /**
     * @return array{partSize: int|null, compression: int|null, tickSeconds: float|null, force: bool, report: string|null}
     */
    private function parseOptions(Input $input): array
    {
        $partSize = null;
        if ($input->has('part-size')) {
            $partSize = Budget::parseBytes((string) $input->value('part-size'));
            if ($partSize === null || $partSize < 1048576) {
                throw new UsageError('--part-size is a size such as 500M or 2G, at least 1M.');
            }
        }
        $compression = null;
        if ($input->has('compression')) {
            if (preg_match('/^\d$/', (string) $input->value('compression')) !== 1) {
                throw new UsageError('--compression is a number from 0 to 9.');
            }
            $compression = (int) $input->value('compression');
        }
        $tickSeconds = null;
        if ($input->has('tick-seconds')) {
            $seconds = $input->value('tick-seconds');
            if ($seconds === null || !is_numeric($seconds) || (float) $seconds < 0) {
                throw new UsageError('--tick-seconds is a number of seconds.');
            }
            $tickSeconds = (float) $seconds;
        }

        return ['partSize' => $partSize, 'compression' => $compression, 'tickSeconds' => $tickSeconds, 'force' => $input->has('force'), 'report' => $input->value('report')];
    }

    /**
     * @param array{partSize: int|null, compression: int|null, tickSeconds: float|null, force: bool, report: string|null} $options
     */
    private function all(ProfileStore $profiles, RunStore $runs, array $options, Output $output, string $data): ExitCode
    {
        $ids = $profiles->ids();
        if ($ids === []) {
            $output->writeln('No profiles yet. Create one with "profile add".');

            return ExitCode::Ok;
        }
        $worst = ExitCode::Ok;
        $reports = [];
        foreach ($ids as $id) {
            try {
                $reports[$id] = $this->backup($id, $profiles, $runs, $options, $output, $data);
            } catch (GuardFailed $e) {
                $output->error("Profile \"$id\" refused: " . $e->getMessage());
                $worst = $worst === ExitCode::Ok ? ExitCode::GuardFailed : $worst;
            } catch (Throwable $e) {
                $output->error("Profile \"$id\" failed: " . $e->getMessage());
                $worst = ExitCode::StepFailed;
            }
        }
        $output->writeln(sprintf('%d of %d profiles backed up.', count($reports), count($ids)));
        $this->writeReport($options['report'], ['profiles' => $reports]);

        return $worst;
    }

    /**
     * @param array{partSize: int|null, compression: int|null, tickSeconds: float|null, force: bool, report: string|null} $options
     */
    private function one(string $id, ProfileStore $profiles, RunStore $runs, array $options, Output $output, string $data): ExitCode
    {
        if (!ProfileStore::validId($id) || !$profiles->exists($id)) {
            throw new UsageError("There is no profile \"$id\". \"profile list\" shows the profiles.");
        }
        $report = $this->backup($id, $profiles, $runs, $options, $output, $data);
        $this->writeReport($options['report'], $report);

        return ExitCode::Ok;
    }

    /**
     * Runs the backup of one profile under the lock of that profile.
     *
     * @param array{partSize: int|null, compression: int|null, tickSeconds: float|null, force: bool, report: string|null} $options
     *
     * @return array<string, mixed> the report
     */
    private function backup(string $id, ProfileStore $profiles, RunStore $runs, array $options, Output $output, string $data): array
    {
        $lock = Lock::tryAcquire($data . '/locks/profile-' . $id . '.lock', 'backup of ' . $id);
        if ($lock === null) {
            throw new GuardFailed("The profile \"$id\" is being backed up by another process.");
        }
        try {
            foreach ($runs->all('backup') as $run) {
                if ($run->status === RunStatus::Running && ($run->state['profileId'] ?? null) === $id && $runs->isInterrupted($run)) {
                    $output->writeln("Note: an interrupted backup of \"$id\" exists ({$run->id}); continue it with --resume={$run->id}.");
                }
            }

            $task = new BackupRun($profiles, $this->plugins ?? PluginRegistry::load(), $this->dataDirectory);
            $executor = $this->executor($runs, $task, $options['tickSeconds']);
            $output->writeln("Backing up \"$id\" ...");
            $run = $executor->start([
                'profile' => $id,
                'force' => $options['force'],
                'snapshot' => $options['tickSeconds'] === null,
                'partSize' => $options['partSize'],
                'compression' => $options['compression'],
            ]);

            return $this->execute($executor, $task, $runs, $run->id, false, $output);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array{partSize: int|null, compression: int|null, tickSeconds: float|null, force: bool, report: string|null} $options
     */
    private function resume(string $runId, ProfileStore $profiles, RunStore $runs, array $options, Output $output): ExitCode
    {
        try {
            $run = $runs->load($runId);
        } catch (EngineException $e) {
            throw new UsageError($e->getMessage(), 0, $e);
        }
        if ($run->task !== 'backup') {
            throw new UsageError("Run \"$runId\" is not a backup.");
        }
        $task = new BackupRun($profiles, $this->plugins ?? PluginRegistry::load(), $this->dataDirectory);
        $executor = $this->executor($runs, $task, $options['tickSeconds']);
        $output->writeln('Continuing the backup of "' . (string) ($run->state['profileId'] ?? '?') . '" (run ' . $runId . ') ...');
        $report = $this->execute($executor, $task, $runs, $runId, true, $output);
        $this->writeReport($options['report'], $report);

        return ExitCode::Ok;
    }

    private function executor(RunStore $runs, BackupRun $task, ?float $tickSeconds): Executor
    {
        return new Executor($runs, $task, $tickSeconds === null ? Budget::forThisProcess(...) : static fn (): Budget => Budget::seconds($tickSeconds));
    }

    /**
     * @return array<string, mixed> the report
     */
    private function execute(Executor $executor, BackupRun $task, RunStore $runs, string $runId, bool $resume, Output $output): array
    {
        $onTick = static function (Run $run) use ($output): void {
            $output->verbose(sprintf('  [tick %d] %s', $run->ticks, $run->progress ?? ''));
        };
        try {
            $run = $resume ? $executor->resume($runId, $onTick) : $executor->execute($runId, null, $onTick);
        } catch (Throwable $e) {
            try {
                $task->abandon($runs->load($runId)->state);
            } catch (Throwable) {
                // the run file is gone or unreadable: nothing to clean up by it
            }
            if ($e instanceof GuardFailed || $e instanceof StepFailed) {
                throw $e;
            }

            throw new StepFailed($e->getMessage(), 0, $e);
        }

        $result = $run->state['result'] ?? null;
        if (!is_array($result)) {
            throw new StepFailed("The run \"$runId\" ended without a result.");
        }
        $runs->delete($runId);
        $this->printResult($result, $output);

        return ['run' => $runId, 'result' => $result];
    }

    /**
     * @param array<array-key, mixed> $result
     */
    private function printResult(array $result, Output $output): void
    {
        $parts = is_array($result['parts'] ?? null) ? $result['parts'] : [];
        $output->writeln(sprintf(
            'Backup %s: %s, %d file(s) and %d director(ies)%s in %d s.',
            (string) ($result['backup'] ?? '?'),
            SpaceCheck::format((int) ($result['size'] ?? 0)),
            (int) ($result['files'] ?? 0),
            (int) ($result['directories'] ?? 0),
            (int) ($result['tables'] ?? 0) > 0 ? sprintf(', %d table(s) with %d row(s)', (int) $result['tables'], (int) ($result['rows'] ?? 0)) : '',
            (int) ($result['duration'] ?? 0),
        ));
        $output->writeln('  ' . (count($parts) === 1 ? 'archive: ' : count($parts) . ' parts: ') . implode(', ', array_map('strval', $parts)));
        $consistency = (string) ($result['consistency'] ?? 'none');
        $output->writeln('  status: ' . (string) ($result['status'] ?? '?') . ($consistency === 'none' ? '' : "; the database was read " . ($consistency === 'snapshot' ? 'in one consistent snapshot' : 'table by table')));
        if ((int) ($result['warnings'] ?? 0) > 0) {
            $output->writeln(sprintf('  %d warning(s); they are in the sidecar file and the manifest (-v shows the first ones).', (int) $result['warnings']));
        }
    }

    /**
     * @param array<string, mixed> $report
     */
    private function writeReport(?string $file, array $report): void
    {
        if ($file === null) {
            return;
        }
        try {
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new StepFailed('Cannot encode the report: ' . $e->getMessage(), 0, $e);
        }
        if (@file_put_contents($file, $json . "\n") === false) {
            throw new StepFailed("Cannot write the report to \"$file\".");
        }
        @chmod($file, 0600);
    }
}
