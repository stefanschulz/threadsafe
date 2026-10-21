<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

use ThreadSafe\Backup\BackupName;
use ThreadSafe\Backup\Verifier;
use ThreadSafe\Core\ExitCode;
use ThreadSafe\Core\Paths;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Storage\BackupCatalog;
use ThreadSafe\Storage\BackupEntry;
use ThreadSafe\Storage\LocalStorage;
use ThreadSafe\Storage\SpaceCheck;
use ThreadSafe\Storage\StorageException;

/**
 * `threadsafe verify <profile> [<backup>]`: checks that a backup is intact and records the result in its sidecar file. The
 * default is the newest backup and the full check (every part is read as a ZIP archive and every entry against its CRC32);
 * `--quick` only compares sizes and SHA-256 sums of the parts. Exit code 3 if anything is wrong.
 */
final class VerifyCommand implements Command
{
    /**
     * @param string|null $dataDirectory profiles; default: the data directory of the program
     */
    public function __construct(private readonly ?string $dataDirectory = null)
    {
    }

    public function name(): string
    {
        return 'verify';
    }

    public function summary(): string
    {
        return 'Check that a backup is intact (default: the newest backup of the profile)';
    }

    public function usage(): string
    {
        return 'verify <profile> [<backup>] [--quick] [--json]';
    }

    public function options(): array
    {
        return [
            new OptionSpec('quick', 'Only compare size and SHA-256 of the parts with the sidecar file'),
            new OptionSpec('json', 'Output as JSON'),
        ];
    }

    public function run(Input $input, Output $output): ExitCode
    {
        $profileId = $input->argument(0) ?? throw new UsageError('Say which profile: verify <profile> [<backup>].');
        if (count($input->arguments()) > 2) {
            throw new UsageError('Unexpected argument "' . $input->arguments()[2] . '".');
        }
        $profiles = new ProfileStore(($this->dataDirectory ?? Paths::data()) . '/profiles');
        if (!ProfileStore::validId($profileId) || !$profiles->exists($profileId)) {
            throw new UsageError("There is no profile \"$profileId\".");
        }
        $storage = new LocalStorage($profiles->load($profileId)->storage);
        $catalog = new BackupCatalog($storage);

        try {
            $entry = $catalog->find($profileId, $input->argument(1));
        } catch (StorageException $e) {
            throw new UsageError($e->getMessage(), 0, $e);
        }
        if ($entry->condition === BackupEntry::UNFINISHED || $entry->condition === BackupEntry::UNKNOWN) {
            $output->error("Backup {$entry->name->base()} cannot be verified: " . ($entry->problem ?? 'nothing usable found') . '.');

            return ExitCode::StepFailed;
        }

        $report = (new Verifier($storage))->verify(BackupName::parse($entry->name->base()) ?? $entry->name, !$input->has('quick'));

        if ($input->has('json')) {
            $output->writeln(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $report->ok() ? ExitCode::Ok : ExitCode::StepFailed;
        }

        foreach ($report->notes as $note) {
            $output->writeln('Note: ' . $note);
        }
        if ($report->ok()) {
            $output->writeln(sprintf(
                'Backup %s is intact (%s check: %d part(s), %s read). Status: %s.',
                $report->backup,
                $report->full ? 'full' : 'quick',
                $report->parts,
                SpaceCheck::format($report->bytes),
                $report->status === null ? '?' : $report->status->value,
            ));

            return ExitCode::Ok;
        }
        $output->error("Backup {$report->backup} is NOT intact:");
        foreach ($report->problems as $problem) {
            $output->error('  - ' . $problem);
        }

        return ExitCode::StepFailed;
    }
}
