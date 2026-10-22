<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

use ThreadSafe\Admin\ConfigException;
use ThreadSafe\App\RetentionService;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\ExitCode;
use ThreadSafe\Core\Paths;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Retention\RetentionAction;
use ThreadSafe\Retention\RetentionPlan;
use ThreadSafe\Storage\SpaceCheck;

/**
 * `threadsafe retention <profile> [--dry-run]`: applies the retention rules of a profile (docs/PHASE5.md 3.4), the same work the
 * trigger does by itself after a backup and once an hour. `--dry-run` shows what would be deleted and why and changes nothing. Applying it
 * by hand also confirms the rules for the trigger: a rule that would delete more than one backup waits for this until it has been seen.
 */
final class RetentionCommand implements Command
{
    /**
     * @param string|null $dataDirectory default: the data directory of the program
     */
    public function __construct(private readonly ?string $dataDirectory = null)
    {
    }

    public function name(): string
    {
        return 'retention';
    }

    public function summary(): string
    {
        return 'Apply the retention rules of a profile (--dry-run: only show what would be deleted)';
    }

    public function usage(): string
    {
        return 'retention <profile> [--dry-run] [--json]';
    }

    public function options(): array
    {
        return [
            new OptionSpec('dry-run', 'Show what would be deleted and why; delete nothing'),
            new OptionSpec('json', 'Output as JSON'),
        ];
    }

    public function run(Input $input, Output $output): ExitCode
    {
        $id = $input->argument(0) ?? throw new UsageError('Say which profile: retention <profile>.');
        if (count($input->arguments()) > 1) {
            throw new UsageError('Unexpected argument "' . $input->arguments()[1] . '".');
        }
        $data = $this->dataDirectory ?? Paths::data();
        $profiles = new ProfileStore($data . '/profiles');
        try {
            $profile = ProfileStore::validId($id) && $profiles->exists($id) ? $profiles->load($id) : throw new UsageError("There is no profile \"$id\".");
            $service = RetentionService::create($data);
        } catch (ProfileException|ConfigException $e) {
            throw new UsageError($e->getMessage(), 0, $e);
        }
        if ($profile->retention === null) {
            throw new UsageError("The profile \"$id\" has no retention rules. Set them with \"profile edit $id --keep=N --max-age-days=D --max-bytes=SIZE\".");
        }

        $dry = $input->has('dry-run');
        $plan = $dry ? $service->preview($profile) : $service->apply($profile);
        if ($input->has('json')) {
            $output->writeln(json_encode(self::toArray($id, $dry, $plan), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->print($id, $dry, $plan, $output);
        }
        if ($plan->blocked) {
            throw new GuardFailed(implode(' ', $plan->notes));
        }

        return ExitCode::Ok;
    }

    private function print(string $id, bool $dry, RetentionPlan $plan, Output $output): void
    {
        $output->writeln(sprintf('Retention of "%s": %d backup%s count, %s together.', $id, $plan->usable, $plan->usable === 1 ? '' : 's', SpaceCheck::format($plan->bytes)));
        foreach ([...$plan->delete, ...$plan->leftovers] as $action) {
            $output->writeln(sprintf('  %s %s  %9s  %s', $dry ? 'would delete' : 'deleted     ', $action->base(), SpaceCheck::format($action->bytes), $action->reason));
        }
        if ($plan->delete === [] && $plan->leftovers === []) {
            $output->writeln('  nothing to delete.');
        } else {
            $output->writeln(sprintf('  %s %s.', $dry ? 'would free' : 'freed', SpaceCheck::format($plan->freed())));
        }
        foreach ($plan->notes as $note) {
            $output->writeln('Note: ' . $note);
        }
        if ($dry && $plan->delete !== []) {
            $output->writeln('Nothing was deleted. Run "retention ' . $id . '" to delete.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(string $id, bool $dry, RetentionPlan $plan): array
    {
        $action = static fn (RetentionAction $a): array => ['backup' => $a->base(), 'rule' => $a->rule, 'reason' => $a->reason, 'bytes' => $a->bytes];

        return [
            'profile' => $id,
            'dryRun' => $dry,
            'usable' => $plan->usable,
            'bytes' => $plan->bytes,
            'delete' => array_map($action, $plan->delete),
            'leftovers' => array_map($action, $plan->leftovers),
            'freed' => $plan->freed(),
            'notes' => $plan->notes,
            'blocked' => $plan->blocked,
        ];
    }
}
