<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

use Throwable;
use ThreadSafe\Admin\AuditLog;
use ThreadSafe\Admin\Config;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\ExitCode;
use ThreadSafe\Core\Home;
use ThreadSafe\Core\Paths;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Engine\Executor;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Update\HealthResult;
use ThreadSafe\Update\HttpHealthCheck;
use ThreadSafe\Update\Rollback;
use ThreadSafe\Update\UpdateException;
use ThreadSafe\Update\UpdateRun;

/**
 * `threadsafe update <package.zip> --sha256=...` and `threadsafe update --rollback` (docs/PHASE7.md 3.5): the update of an installation
 * from a release, from a shell. The same task as the System page runs, to its end in one process. The checksum is the one from the release
 * page; without it nothing is installed. Without `--url` the new version is not started to check it, which the result says.
 */
final class UpdateCommand implements Command
{
    /**
     * @param string|null $dataDirectory the data directory; default: the one of the program
     * @param string|null $home          the home of the installation; default: the one the program runs in
     */
    public function __construct(private readonly ?string $dataDirectory = null, private readonly ?string $home = null)
    {
    }

    public function name(): string
    {
        return 'update';
    }

    public function summary(): string
    {
        return 'Update ThreadSafe from a release package (checked by its SHA-256), or go back to the previous release';
    }

    public function usage(): string
    {
        return 'update <package.zip> --sha256=HEX [--url=ADDRESS] [--downgrade] | update --rollback';
    }

    public function options(): array
    {
        return [
            new OptionSpec('sha256', 'the SHA-256 of the package from the release page (SHA256SUMS); required', takesValue: true, valueName: 'HEX'),
            new OptionSpec('url', 'the address of the web interface (https://example.org/threadsafe): the new version is asked there to answer, and the update is taken back if it does not', takesValue: true, valueName: 'ADDRESS'),
            new OptionSpec('downgrade', 'allow a version older than the installed one'),
            new OptionSpec('rollback', 'go back to the previous release (the data stays as it is)'),
        ];
    }

    public function run(Input $input, Output $output): ExitCode
    {
        $home = $this->home ?? Paths::home() ?? throw new GuardFailed('This ThreadSafe is installed from a clone, not from a release. Update it with git pull and composer install.');
        $data = $this->dataDirectory ?? Paths::data();

        if ($input->has('rollback')) {
            if ($input->arguments() !== []) {
                throw new UsageError('--rollback takes no package.');
            }
            try {
                $done = Rollback::run($home, $data);
            } catch (UpdateException $e) {
                throw new GuardFailed($e->getMessage(), 0, $e);
            }
            $this->audit($data, 'update.rolled_back', ['from' => $done['from'], 'to' => $done['to'], 'by' => 'command']);
            $output->writeln("Version {$done['to']} is active again (was {$done['from']}). The data was not touched.");

            return ExitCode::Ok;
        }

        $package = $input->argument(0) ?? throw new UsageError('Give the package: update <package.zip> --sha256=HEX.');
        if (count($input->arguments()) > 1) {
            throw new UsageError('Unexpected argument "' . $input->arguments()[1] . '".');
        }
        $sha = $input->has('sha256') ? (string) $input->value('sha256') : throw new UsageError('--sha256 is required: the checksum of the package from the release page.');

        $health = null;
        if ($input->has('url')) {
            $url = (string) $input->value('url');
            $parts = parse_url($url);
            if (!is_array($parts) || !isset($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
                throw new UsageError('--url must be an address such as https://example.org/threadsafe.');
            }
            $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            $health = static fn (string $home, string $data, string $version): HealthResult => (new HttpHealthCheck($url, $host))->check($data, $version);
        }

        $store = new RunStore($data . '/runs');
        $executor = new Executor($store, new UpdateRun($health), Budget::unlimited(...));
        $run = $executor->start(['file' => $package, 'sha256' => $sha, 'home' => $home, 'data' => $data, 'allowDowngrade' => $input->has('downgrade')]);
        $this->audit($data, 'update.started', ['run' => $run->id, 'file' => basename($package)]);
        try {
            $run = $executor->execute($run->id, null, static function ($tick) use ($output): void {
                $output->verbose((string) $tick->progress);
            });
        } catch (Throwable $e) {
            $store->delete($run->id);
            $this->audit($data, 'update.failed', ['run' => $run->id, 'reason' => $e->getMessage()]);
            throw new GuardFailed($e->getMessage() . ' The installation is as it was.', 0, $e);
        }
        $result = (array) ($run->state['result'] ?? []);
        $store->delete($run->id);
        if ($run->status !== RunStatus::Finished) {
            throw new GuardFailed('The update did not finish.');
        }
        if ($result['rolledBack'] ?? false) {
            $this->audit($data, 'update.rolled_back', ['from' => (string) $result['from'], 'to' => (string) $result['to'], 'health' => (string) $result['health']]);
            $output->error("Version {$result['to']} did not start, so it was taken back: {$result['message']}");
            $output->writeln("Version {$result['from']} is active again, with the state files as they were.");

            return ExitCode::StepFailed;
        }
        $this->audit($data, 'update.applied', ['from' => (string) $result['from'], 'to' => (string) $result['to'], 'health' => (string) $result['health']]);
        $output->writeln("Updated from {$result['from']} to {$result['to']}. " . (string) ($result['message'] ?? ''));
        if (($result['health'] ?? '') === HealthResult::SKIPPED) {
            $output->writeln('The new version was not started to check it (give --url to do that). If it does not work: php ' . Home::releaseDirectory($home, (string) $result['from']) . '/bin/threadsafe update --rollback');
        }
        $output->writeln('The previous release ' . $result['from'] . ' is kept for a roll-back.');

        return ExitCode::Ok;
    }

    /**
     * @param array<string, string> $details
     */
    private function audit(string $data, string $event, array $details): void
    {
        try {
            (new AuditLog($data . '/audit.log', Config::load($data . '/config.json')->auditMaxBytes()))->record($event, 'command', '', $details);
        } catch (Throwable) {
            // the audit log is a record, not a condition
        }
    }
}
