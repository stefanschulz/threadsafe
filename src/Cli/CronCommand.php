<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

use ThreadSafe\Admin\ConfigException;
use ThreadSafe\App\TriggerService;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\ExitCode;
use ThreadSafe\Core\Paths;

/**
 * `threadsafe cron`: the trigger (docs/PHASE5.md 3.3). One cron job calls it every few minutes (or once a day: the grace period of the
 * schedule makes up a run that was missed by less than six hours). It writes the heartbeat, continues a backup that was interrupted, and
 * runs every scheduled backup that is due, each to its end, with the database read in one consistent snapshot. It ends at once when
 * another trigger is working. `-q` prints only problems, which is what a cron job should mail.
 */
final class CronCommand implements Command
{
    /**
     * @param string|null $dataDirectory profiles, runs, locks and schedule state; default: the data directory of the program
     */
    public function __construct(private readonly ?string $dataDirectory = null)
    {
    }

    public function name(): string
    {
        return 'cron';
    }

    public function summary(): string
    {
        return 'The trigger: run the scheduled backups that are due (call this from cron; -q prints only problems)';
    }

    public function usage(): string
    {
        return 'cron';
    }

    public function options(): array
    {
        return [];
    }

    public function run(Input $input, Output $output): ExitCode
    {
        if ($input->arguments() !== []) {
            throw new UsageError('Unexpected argument "' . $input->arguments()[0] . '".');
        }
        try {
            $trigger = TriggerService::create($this->dataDirectory ?? Paths::data());
        } catch (ConfigException $e) {
            throw new GuardFailed($e->getMessage(), 0, $e);
        }

        return ExitCode::from($trigger->work(static function (string $line, bool $problem) use ($output): void {
            $problem ? $output->error($line) : $output->writeln($line);
        }));
    }
}
