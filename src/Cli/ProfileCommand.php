<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

use ThreadSafe\App\ProfileService;
use DateTimeImmutable;
use ThreadSafe\Admin\Config;
use ThreadSafe\Admin\ConfigException;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\ExitCode;
use ThreadSafe\Core\Paths;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Profile\Check;
use ThreadSafe\Profile\CheckLevel;
use ThreadSafe\Profile\ConnectionTest;
use ThreadSafe\Profile\ProfileException;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Profile\TestReport;
use ThreadSafe\Schedule\ScheduleException;

/**
 * `threadsafe profile add|edit|show|list|test|remove`: manages the site profiles in `data/profiles/`. A profile says which
 * site to back up and where the backups go; `add` and `edit` test it first and refuse to store one that cannot work.
 */
final class ProfileCommand implements Command
{
    private ?PluginRegistry $plugins;

    /**
     * @param string|null         $dataDirectory where profiles live; default: the data directory of the program
     * @param PluginRegistry|null $plugins       the website types; default: the plugins of the program
     */
    public function __construct(private readonly ?string $dataDirectory = null, ?PluginRegistry $plugins = null)
    {
        $this->plugins = $plugins;
    }

    public function name(): string
    {
        return 'profile';
    }

    public function summary(): string
    {
        return 'Manage site profiles: add, edit, show, list, test, remove';
    }

    public function usage(): string
    {
        return 'profile add <id> --type=TYPE --path=DIR --storage=DIR [options] | edit <id> [options] | show <id> | list | test <id> | remove <id> --yes';
    }

    public function options(): array
    {
        return [
            new OptionSpec('type', 'Website type: ' . implode(', ', $this->registry()->ids()), takesValue: true, valueName: 'TYPE'),
            new OptionSpec('path', 'Directory of the site (absolute)', takesValue: true, valueName: 'DIR'),
            new OptionSpec('storage', 'Directory for the backups of this site (absolute; outside the web root)', takesValue: true, valueName: 'DIR'),
            new OptionSpec('name', 'Display name (default: the id)', takesValue: true, valueName: 'NAME'),
            new OptionSpec('note', 'Free text', takesValue: true, valueName: 'TEXT'),
            new OptionSpec('db-host', 'Database host (types without a configuration file to read)', takesValue: true, valueName: 'HOST'),
            new OptionSpec('db-port', 'Database port (default 3306)', takesValue: true, valueName: 'PORT'),
            new OptionSpec('db-name', 'Database name', takesValue: true, valueName: 'NAME'),
            new OptionSpec('db-user', 'Database user', takesValue: true, valueName: 'USER'),
            new OptionSpec('db-password-file', 'File containing the database password (or set THREADSAFE_DB_PASSWORD)', takesValue: true, valueName: 'FILE'),
            new OptionSpec('db-driver', 'PHP interface to the database: auto, pdo or mysqli', takesValue: true, valueName: 'DRIVER'),
            new OptionSpec('prefix', 'Table prefix', takesValue: true, valueName: 'PREFIX'),
            new OptionSpec('no-database', 'edit: forget the explicit database settings (read them from the site, or none)'),
            new OptionSpec('exclude', 'Path pattern to leave out, relative to the site (repeatable; edit replaces the list)', takesValue: true, valueName: 'PATTERN', repeatable: true),
            new OptionSpec('structure-only', 'Table of which only the structure is kept (repeatable; edit replaces the list)', takesValue: true, valueName: 'TABLE', repeatable: true),
            new OptionSpec('no-default-filters', 'Do not use the filters the website type brings'),
            new OptionSpec('default-filters', 'edit: use the filters the website type brings again'),
            new OptionSpec('compression', 'Deflate level 0 (store) to 9, default 6', takesValue: true, valueName: 'LEVEL'),
            new OptionSpec('part-size', 'Split archives into parts of this size, e.g. 2G or 500M (at least 1M)', takesValue: true, valueName: 'SIZE'),
            new OptionSpec('no-part-size', 'edit: do not split archives'),
            new OptionSpec('schedule', 'Back up by itself: a cron expression such as "30 3 * * *" (minute hour day-of-month month day-of-week), read in the time zone of the installation', takesValue: true, valueName: 'CRON'),
            new OptionSpec('no-schedule', 'edit: no scheduled backups any more'),
            new OptionSpec('keep', 'Retention: keep at most this many backups', takesValue: true, valueName: 'N'),
            new OptionSpec('max-age-days', 'Retention: remove backups older than this many days', takesValue: true, valueName: 'DAYS'),
            new OptionSpec('max-bytes', 'Retention: remove the oldest backups until the rest is below this size, e.g. 20G', takesValue: true, valueName: 'SIZE'),
            new OptionSpec('no-retention', 'edit: no retention rules any more'),
            new OptionSpec('recipient', 'Encrypt backups for this public key (age1..., repeatable; edit replaces the list)', takesValue: true, valueName: 'KEY', repeatable: true),
            new OptionSpec('no-encryption', 'edit: do not encrypt new backups'),
            new OptionSpec('no-test', 'add, edit: store the profile without testing it first'),
            new OptionSpec('json', 'show, list, test: output as JSON'),
            new OptionSpec('yes', 'remove: really remove the profile (its backups stay)'),
        ];
    }

    public function run(Input $input, Output $output): ExitCode
    {
        $action = $input->argument(0) ?? throw new UsageError('Say what to do: add, edit, show, list, test or remove.');
        $id = $input->argument(1);
        $store = new ProfileStore(($this->dataDirectory ?? Paths::data()) . '/profiles');
        if (count($input->arguments()) > ($action === 'list' ? 1 : 2)) {
            throw new UsageError('Unexpected argument "' . $input->arguments()[$action === 'list' ? 1 : 2] . '".');
        }

        return match ($action) {
            'list' => $this->list($store, $input, $output),
            'add', 'edit', 'show', 'test', 'remove' => $this->forProfile($action, $id ?? throw new UsageError("profile $action needs the id of a profile."), $store, $input, $output),
            default => throw new UsageError("Unknown action \"$action\" (add, edit, show, list, test, remove)."),
        };
    }

    private function registry(): PluginRegistry
    {
        return $this->plugins ??= PluginRegistry::load();
    }

    private function service(ProfileStore $store): ProfileService
    {
        return new ProfileService($store, $this->registry(), $this->dataDirectory);
    }

    private function forProfile(string $action, string $id, ProfileStore $store, Input $input, Output $output): ExitCode
    {
        if (!ProfileStore::validId($id)) {
            throw new UsageError('A profile id is 1-40 lower-case letters, digits, hyphens or underscores.');
        }

        return match ($action) {
            'add' => $this->add($id, $store, $input, $output),
            'edit' => $this->edit($id, $store, $input, $output),
            'show' => $this->show($this->load($store, $id), $input, $output),
            'test' => $this->test($this->load($store, $id), $store, $input, $output),
            default => $this->remove($id, $store, $input, $output),
        };
    }

    private function load(ProfileStore $store, string $id): SiteProfile
    {
        try {
            return $store->load($id);
        } catch (ProfileException $e) {
            throw new UsageError($e->getMessage(), 0, $e);
        }
    }

    private function add(string $id, ProfileStore $store, Input $input, Output $output): ExitCode
    {
        if ($store->exists($id)) {
            throw new UsageError("The profile \"$id\" exists already; use \"profile edit $id\".");
        }
        foreach (['type', 'path', 'storage'] as $required) {
            if (!$input->has($required)) {
                throw new UsageError("profile add needs --$required.");
            }
        }
        $data = ['id' => $id, ...$this->fields($input, null)];

        return $this->store($store, $data, $input, $output, 'added');
    }

    private function edit(string $id, ProfileStore $store, Input $input, Output $output): ExitCode
    {
        $current = $this->load($store, $id);
        $changes = $this->fields($input, $current);
        if ($changes === []) {
            throw new UsageError('Nothing to change: give at least one option.');
        }

        return $this->store($store, [...$current->toArray(), ...$changes], $input, $output, 'changed');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function store(ProfileStore $store, array $data, Input $input, Output $output, string $verb): ExitCode
    {
        try {
            $profile = SiteProfile::fromArray($data);
        } catch (ProfileException $e) {
            throw new UsageError($e->getMessage(), 0, $e);
        }

        $result = $this->service($store)->store($profile, test: !$input->has('no-test'));
        if ($result->report !== null) {
            $this->printReport($result->report, $output);
        }
        if ($result->rejectedByTest()) {
            throw new GuardFailed("The profile \"{$profile->id}\" was not stored because it cannot be backed up as it is. Fix the problems above, or use --no-test to store it anyway.");
        }
        if ($result->error !== null) {
            throw new GuardFailed($result->error);
        }
        $output->writeln("Profile \"{$profile->id}\" $verb.");

        return ExitCode::Ok;
    }

    /**
     * The profile fields the options ask for.
     *
     * @return array<string, mixed>
     */
    private function fields(Input $input, ?SiteProfile $current): array
    {
        $data = [];
        foreach (['type', 'path', 'storage', 'name', 'note'] as $field) {
            if ($input->has($field)) {
                $data[$field] = (string) $input->value($field);
            }
        }
        if ($input->has('exclude')) {
            $data['excludePaths'] = $input->values('exclude');
        }
        if ($input->has('structure-only')) {
            $data['structureOnlyTables'] = $input->values('structure-only');
        }
        if ($input->has('no-default-filters') && $input->has('default-filters')) {
            throw new UsageError('--no-default-filters and --default-filters contradict each other.');
        }
        if ($input->has('no-default-filters') || $input->has('default-filters')) {
            $data['useDefaultFilters'] = $input->has('default-filters');
        }
        if ($input->has('compression')) {
            $level = $input->value('compression');
            if ($level === null || preg_match('/^\d$/', $level) !== 1) {
                throw new UsageError('--compression is a number from 0 to 9.');
            }
            $data['compression'] = (int) $level;
        }
        if ($input->has('part-size') && $input->has('no-part-size')) {
            throw new UsageError('--part-size and --no-part-size contradict each other.');
        }
        if ($input->has('part-size')) {
            $data['partSize'] = self::size((string) $input->value('part-size'));
        } elseif ($input->has('no-part-size')) {
            $data['partSize'] = null;
        }

        $database = $this->database($input, $current);
        if ($database !== false) {
            $data['database'] = $database;
        }

        return $data + $this->schedulingFields($input, $current);
    }

    /**
     * The schedule, retention and encryption the options ask for.
     *
     * @return array<string, mixed>
     */
    private function schedulingFields(Input $input, ?SiteProfile $current): array
    {
        $data = [];
        if ($input->has('schedule') && $input->has('no-schedule')) {
            throw new UsageError('--schedule and --no-schedule contradict each other.');
        }
        if ($input->has('schedule')) {
            $data['schedule'] = ['cron' => (string) $input->value('schedule')];
        } elseif ($input->has('no-schedule')) {
            $data['schedule'] = null;
        }

        $rules = array_values(array_filter(['keep', 'max-age-days', 'max-bytes'], $input->has(...)));
        if ($rules !== [] && $input->has('no-retention')) {
            throw new UsageError('--no-retention contradicts --' . implode(', --', $rules) . '.');
        }
        if ($input->has('no-retention')) {
            $data['retention'] = null;
        } elseif ($rules !== []) {
            // a rule that is not named stays as it is: "--keep=10" does not forget the age limit
            $retention = $current?->retention?->toArray() ?? [];
            foreach (['keep' => 'keep', 'max-age-days' => 'maxAgeDays'] as $option => $key) {
                if ($input->has($option)) {
                    $value = $input->value($option);
                    if ($value === null || preg_match('/^\d{1,6}$/', $value) !== 1) {
                        throw new UsageError("--$option is a whole number.");
                    }
                    $retention[$key] = (int) $value;
                }
            }
            if ($input->has('max-bytes')) {
                $bytes = Budget::parseBytes((string) $input->value('max-bytes'));
                if ($bytes === null) {
                    throw new UsageError('--max-bytes is a size such as 500M or 20G.');
                }
                $retention['maxBytes'] = $bytes;
            }
            $data['retention'] = $retention;
        }

        if ($input->has('recipient') && $input->has('no-encryption')) {
            throw new UsageError('--recipient and --no-encryption contradict each other.');
        }
        if ($input->has('recipient')) {
            $data['encryption'] = ['recipients' => $input->values('recipient')];
        } elseif ($input->has('no-encryption')) {
            $data['encryption'] = null;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>|null|false false = the options say nothing about the database
     */
    private function database(Input $input, ?SiteProfile $current): array|null|false
    {
        if ($input->has('no-database')) {
            if ($current === null) {
                throw new UsageError('--no-database only makes sense with "profile edit".');
            }

            return null;
        }
        $options = ['db-host' => 'host', 'db-port' => 'port', 'db-name' => 'name', 'db-user' => 'user', 'db-driver' => 'driver', 'prefix' => 'prefix'];
        $given = array_filter(array_keys($options), $input->has(...));
        if ($given === [] && !$input->has('db-password-file')) {
            return false;
        }

        $database = $current?->toArray()['database'] ?? [];
        $database = is_array($database) ? $database : [];
        foreach ($options as $option => $key) {
            if ($input->has($option)) {
                $value = (string) $input->value($option);
                if ($key === 'port') {
                    if (preg_match('/^\d{1,5}$/', $value) !== 1) {
                        throw new UsageError('--db-port is a port number.');
                    }
                    $database[$key] = (int) $value;
                } else {
                    $database[$key] = $value;
                }
            }
        }
        if ($input->has('db-password-file')) {
            $file = (string) $input->value('db-password-file');
            $content = is_file($file) ? @file_get_contents($file) : false;
            if ($content === false) {
                throw new UsageError("The password file \"$file\" cannot be read.");
            }
            $database['password'] = rtrim($content, "\r\n");
        } elseif (!isset($database['password'])) {
            $fromEnvironment = getenv('THREADSAFE_DB_PASSWORD');
            $database['password'] = is_string($fromEnvironment) ? $fromEnvironment : '';
        }

        return $database;
    }

    private static function size(string $text): int
    {
        $bytes = Budget::parseBytes($text);
        if ($bytes === null || $bytes < SiteProfile::MIN_PART_SIZE) {
            throw new UsageError('--part-size is a size such as 500M or 2G, at least 1M.');
        }

        return $bytes;
    }

    private function show(SiteProfile $profile, Input $input, Output $output): ExitCode
    {
        $data = $profile->toArray(withPassword: false);
        if ($input->has('json')) {
            $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return ExitCode::Ok;
        }
        $output->writeln("Profile {$profile->id}" . ($profile->name !== $profile->id ? " ({$profile->name})" : ''));
        $output->writeln("  type:       {$profile->type}");
        $output->writeln("  site:       {$profile->path}");
        $output->writeln("  storage:    {$profile->storage}");
        $output->writeln('  database:   ' . ($profile->database === null ? "from the site's configuration, or none" : sprintf('%s@%s:%d/%s (prefix "%s", password hidden)', $profile->database->user, $profile->database->host, $profile->database->port, $profile->database->name, $profile->database->prefix)));
        $output->writeln('  filters:    ' . ($profile->useDefaultFilters ? 'the defaults of the type' : 'no defaults') . ($profile->excludePaths === [] ? '' : ', also leaving out ' . implode(', ', $profile->excludePaths)));
        if ($profile->structureOnlyTables !== []) {
            $output->writeln('  structure only: ' . implode(', ', $profile->structureOnlyTables));
        }
        $output->writeln("  compression: {$profile->compression}" . ($profile->partSize === null ? ', one archive' : ', parts of ' . round($profile->partSize / 1048576) . ' MB'));
        $this->showScheduling($profile, $output);
        if ($profile->note !== '') {
            $output->writeln("  note:       {$profile->note}");
        }

        return ExitCode::Ok;
    }

    private function showScheduling(SiteProfile $profile, Output $output): void
    {
        if ($profile->schedule !== null) {
            $text = "  schedule:   {$profile->schedule->cron}";
            try {
                $zone = Config::load(($this->dataDirectory ?? Paths::data()) . '/config.json')->scheduleZone();
                $next = $profile->schedule->expression()->next(new DateTimeImmutable('now', $zone));
                $text .= ' (time zone ' . $zone->getName() . ', next run ' . $next->format('Y-m-d H:i T') . ')';
            } catch (ConfigException|ScheduleException $e) {
                $text .= ' (' . $e->getMessage() . ')';
            }
            $output->writeln($text);
        }
        if ($profile->retention !== null) {
            $rules = [
                $profile->retention->keep === null ? null : 'keep ' . $profile->retention->keep,
                $profile->retention->maxAgeDays === null ? null : $profile->retention->maxAgeDays . ' days at most',
                $profile->retention->maxBytes === null ? null : round($profile->retention->maxBytes / 1048576) . ' MB at most',
            ];
            $output->writeln('  retention:  ' . implode(', ', array_filter($rules)));
        }
        if ($profile->encryption !== null) {
            $keys = array_map(static fn (string $key): string => substr($key, 0, 12) . '...' . substr($key, -6), $profile->encryption->recipients);
            $output->writeln('  encrypted for ' . count($keys) . ' public key' . (count($keys) === 1 ? '' : 's') . ': ' . implode(', ', $keys));
        }
    }

    private function list(ProfileStore $store, Input $input, Output $output): ExitCode
    {
        ['profiles' => $profiles, 'damaged' => $broken] = $this->service($store)->listing();
        if ($input->has('json')) {
            $output->writeln(json_encode(array_map(static fn (SiteProfile $p): array => $p->toArray(withPassword: false), $profiles), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return ExitCode::Ok;
        }
        if ($profiles === [] && $broken === []) {
            $output->writeln('No profiles yet. Create one with "profile add".');

            return ExitCode::Ok;
        }
        $width = max(array_map(static fn (SiteProfile $p): int => strlen($p->id), $profiles) ?: [2]);
        foreach ($profiles as $profile) {
            $output->writeln(sprintf('%s  %s  %s', str_pad($profile->id, $width), str_pad($profile->type, 12), $profile->path));
        }
        foreach ($broken as $id) {
            $output->error("Profile \"$id\" is damaged: run \"profile show $id\" to see why.");
        }

        return ExitCode::Ok;
    }

    private function test(SiteProfile $profile, ProfileStore $store, Input $input, Output $output): ExitCode
    {
        $report = $this->service($store)->test($profile);
        if ($input->has('json')) {
            $output->writeln(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->printReport($report, $output, everything: true);
            $output->writeln($report->passed() ? 'The profile can be backed up.' : 'The profile cannot be backed up as it is.');
        }

        return $report->passed() ? ExitCode::Ok : ExitCode::GuardFailed;
    }

    private function remove(string $id, ProfileStore $store, Input $input, Output $output): ExitCode
    {
        $this->load($store, $id);
        if (!$input->has('yes')) {
            throw new UsageError("Removing profile \"$id\" needs --yes. Its backups are not touched.");
        }
        $this->service($store)->remove($id);
        $output->writeln("Profile \"$id\" removed. Its backups stay where they are.");

        return ExitCode::Ok;
    }

    private function printReport(TestReport $report, Output $output, bool $everything = false): void
    {
        foreach ($report->checks as $check) {
            $line = sprintf('  %-7s %s: %s', self::label($check), $check->subject, $check->message);
            if (!$everything && ($check->level === CheckLevel::Info || $check->level === CheckLevel::Ok)) {
                $output->verbose($line);
            } else {
                $output->writeln($line);
            }
        }
    }

    private static function label(Check $check): string
    {
        return match ($check->level) {
            CheckLevel::Ok => 'ok',
            CheckLevel::Info => 'info',
            CheckLevel::Warning => 'WARNING',
            CheckLevel::Failure => 'FAILED',
        };
    }
}
