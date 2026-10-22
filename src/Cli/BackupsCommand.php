<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

use ThreadSafe\App\BackupService;
use ThreadSafe\App\CatalogService;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Storage\StorageException;
use ThreadSafe\Backup\BackupName;
use ThreadSafe\Backup\BackupRun;
use ThreadSafe\Core\ExitCode;
use ThreadSafe\Core\Paths;
use ThreadSafe\Engine\EngineException;
use ThreadSafe\Engine\Lock;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Storage\BackupCatalog;
use ThreadSafe\Storage\BackupEntry;
use ThreadSafe\Storage\LocalStorage;
use ThreadSafe\Storage\SpaceCheck;

/**
 * `threadsafe backups [<profile>]`: lists the backups in the storage directories (read from the sidecar files; a missing one
 * is made again from the last archive part), the backups and runs that were interrupted, and - as plain manual commands until
 * retention exists - deletes a backup (`--delete`) or gives up an interrupted run (`--discard-run`).
 */
final class BackupsCommand implements Command
{
    /**
     * @param string|null $dataDirectory profiles, runs and locks; default: the data directory of the program
     */
    public function __construct(private readonly ?string $dataDirectory = null, private readonly ?PluginRegistry $plugins = null)
    {
    }

    public function name(): string
    {
        return 'backups';
    }

    public function summary(): string
    {
        return 'List the backups of the profiles, interrupted runs; delete a backup';
    }

    public function usage(): string
    {
        return 'backups [<profile>] [--json] | backups <profile> --pin=BACKUP | --unpin=BACKUP | backups <profile> --delete=BACKUP --yes | backups --discard-run=RUN --yes';
    }

    public function options(): array
    {
        return [
            new OptionSpec('json', 'Output as JSON'),
            new OptionSpec('pin', 'Pin this backup: retention never deletes it (give the profile too)', takesValue: true, valueName: 'BACKUP'),
            new OptionSpec('unpin', 'Let a pinned backup go again (give the profile too)', takesValue: true, valueName: 'BACKUP'),
            new OptionSpec('delete', 'Delete this backup (all its files; give the profile too)', takesValue: true, valueName: 'BACKUP'),
            new OptionSpec('discard-run', 'Give up an interrupted run and remove what it left in the storage', takesValue: true, valueName: 'RUN'),
            new OptionSpec('yes', 'Really delete'),
        ];
    }

    public function run(Input $input, Output $output): ExitCode
    {
        $data = $this->dataDirectory ?? Paths::data();
        $profiles = new ProfileStore($data . '/profiles');
        $runs = new RunStore($data . '/runs');
        if (count($input->arguments()) > 1) {
            throw new UsageError('Unexpected argument "' . $input->arguments()[1] . '".');
        }
        $only = $input->argument(0);

        if ($input->has('discard-run')) {
            return $this->discardRun((string) $input->value('discard-run'), $profiles, $runs, $input, $output);
        }
        if ($input->has('pin') || $input->has('unpin')) {
            if ($input->has('pin') && $input->has('unpin')) {
                throw new UsageError('--pin and --unpin contradict each other.');
            }
            $pinned = $input->has('pin');

            return $this->pin($only ?? throw new UsageError('--pin and --unpin need the profile: backups <profile> --pin=BACKUP.'), (string) $input->value($pinned ? 'pin' : 'unpin'), $pinned, $profiles, $runs, $output);
        }
        if ($input->has('delete')) {
            return $this->delete($only ?? throw new UsageError('--delete needs the profile: backups <profile> --delete=BACKUP --yes.'), (string) $input->value('delete'), $profiles, $input, $output);
        }

        $selected = [];
        if ($only !== null) {
            if (!ProfileStore::validId($only) || !$profiles->exists($only)) {
                throw new UsageError("There is no profile \"$only\".");
            }
            $selected = [$profiles->load($only)];
        } else {
            $selected = $profiles->all();
        }

        $rows = [];
        $notes = [];
        foreach ($selected as $profile) {
            if (!is_dir($profile->storage)) {
                continue;
            }
            $catalog = new BackupCatalog(new LocalStorage($profile->storage));
            foreach ($catalog->entries($profile->id) as $entry) {
                $rows[] = [$profile, $entry];
                if ($entry->rebuilt) {
                    $notes[] = "The sidecar file of {$entry->name->base()} was missing and has been made again from the archive (status unverified; run \"verify {$profile->id} {$entry->name->base()}\").";
                }
            }
        }
        $interrupted = $this->interrupted($runs, $only);

        if ($input->has('json')) {
            $output->writeln(json_encode([
                'backups' => array_map(static fn (array $row): array => $row[1]->toArray($row[0]->id), $rows),
                'interruptedRuns' => $interrupted,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return ExitCode::Ok;
        }

        if ($rows === []) {
            $output->writeln($selected === [] ? 'No profiles yet. Create one with "profile add".' : 'No backups yet. Make one with "backup <profile>".');
        }
        foreach ($rows as [$profile, $entry]) {
            $output->writeln($this->line($entry));
            if ($entry->problem !== null) {
                $output->writeln('    ' . $entry->problem);
            }
        }
        foreach ($notes as $note) {
            $output->writeln('Note: ' . $note);
        }
        foreach ($interrupted as $run) {
            $output->writeln("Interrupted run {$run['run']} (profile {$run['profile']}): continue it with \"backup --resume={$run['run']}\" or give it up with \"backups --discard-run={$run['run']} --yes\".");
        }

        return ExitCode::Ok;
    }

    private function line(BackupEntry $entry): string
    {
        $sidecar = $entry->sidecar;
        $parts = $sidecar === null ? '-' : count($sidecar->parts) . ' part' . (count($sidecar->parts) === 1 ? '' : 's');
        $application = $sidecar === null ? '' : trim($sidecar->manifest->application['name'] . ' ' . ($sidecar->manifest->application['version'] ?? ''));

        return sprintf(
            '%-38s %s  %9s  %-8s %-17s %s',
            $entry->name->base(),
            $entry->name->time->format('Y-m-d H:i') . ' UTC',
            $sidecar === null ? '-' : SpaceCheck::format($sidecar->size()),
            $parts,
            $entry->label() . ($sidecar?->pinned === true ? ' pinned' : ''),
            $application,
        );
    }

    /**
     * @return list<array{run: string, profile: string, progress: string|null}>
     */
    private function interrupted(RunStore $runs, ?string $profile): array
    {
        $found = [];
        foreach ($runs->all('backup') as $run) {
            $of = is_string($run->state['profileId'] ?? null) ? $run->state['profileId'] : '?';
            if ($run->status === RunStatus::Running && $runs->isInterrupted($run) && ($profile === null || $profile === $of)) {
                $found[] = ['run' => $run->id, 'profile' => $of, 'progress' => $run->progress];
            }
        }

        return $found;
    }

    private function pin(string $profileId, string $backup, bool $pinned, ProfileStore $profiles, RunStore $runs, Output $output): ExitCode
    {
        if (!ProfileStore::validId($profileId) || !$profiles->exists($profileId)) {
            throw new UsageError("There is no profile \"$profileId\".");
        }
        try {
            (new CatalogService($profiles, $runs))->pin($profileId, $backup, $pinned);
        } catch (ProfileException|StorageException $e) {
            throw new UsageError($e->getMessage(), 0, $e);
        }
        $output->writeln($pinned ? "Backup \"$backup\" is pinned: retention never deletes it." : "Backup \"$backup\" is not pinned any more.");

        return ExitCode::Ok;
    }

    private function delete(string $profileId, string $backup, ProfileStore $profiles, Input $input, Output $output): ExitCode
    {
        if (!ProfileStore::validId($profileId) || !$profiles->exists($profileId)) {
            throw new UsageError("There is no profile \"$profileId\".");
        }
        $profile = $profiles->load($profileId);
        $storage = new LocalStorage($profile->storage);
        $name = BackupName::parse($backup);
        if ($name === null || $name->profile !== $profileId || $storage->files($name) === []) {
            throw new UsageError("There is no backup \"$backup\" of profile \"$profileId\".");
        }
        if (!$input->has('yes')) {
            throw new UsageError("Deleting backup \"$backup\" needs --yes. It removes the archive, its parts and the sidecar file for good.");
        }
        $storage->removeBackup($name);
        $output->writeln("Backup \"$backup\" deleted.");

        return ExitCode::Ok;
    }

    private function discardRun(string $runId, ProfileStore $profiles, RunStore $runs, Input $input, Output $output): ExitCode
    {
        try {
            $run = $runs->load($runId);
        } catch (EngineException $e) {
            throw new UsageError($e->getMessage(), 0, $e);
        }
        if ($run->task !== 'backup') {
            throw new UsageError("Run \"$runId\" is not a backup.");
        }
        if ($run->status === RunStatus::Running && (new BackupService($profiles, $this->plugins ?? PluginRegistry::load(), $runs, $this->dataDirectory))->state($run) === 'active') {
            throw new UsageError("Run \"$runId\" is still being executed by a process.");
        }
        if (!$input->has('yes')) {
            throw new UsageError("Giving up run \"$runId\" needs --yes. It removes the unfinished archive and work files of that run.");
        }
        (new BackupRun($profiles, $this->plugins ?? PluginRegistry::load(), $this->dataDirectory))->abandon($run->state);
        $runs->delete($runId);
        $output->writeln("Run \"$runId\" given up; what it left in the storage is removed.");

        return ExitCode::Ok;
    }
}
