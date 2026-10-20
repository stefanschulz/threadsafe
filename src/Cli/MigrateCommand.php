<?php

declare(strict_types=1);

namespace ThreadSafe\Cli;

use Closure;
use InvalidArgumentException;
use JsonException;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\ExitCode;
use ThreadSafe\Core\FileJournal;
use ThreadSafe\Core\Journal;
use ThreadSafe\Core\LazyJournal;
use ThreadSafe\Core\NullJournal;
use ThreadSafe\Core\Runner;
use ThreadSafe\Core\Step;
use ThreadSafe\Plugin\PluginError;
use ThreadSafe\Plugin\PluginRegistry;
use ThreadSafe\Plugin\WebsiteType;

/**
 * `threadsafe migrate <website type>`: turns a restored copy of a site into a working site on a new domain, path or
 * database (or, without a new domain, into a working restore in place). The website type - a plugin - decides what that
 * means for the application: its own options, its request settings and its steps.
 *
 * Without --yes the command only plans and reports (dry run). With --yes it applies the plan, writes a journal
 * of everything it changes and reports what it did.
 */
final class MigrateCommand implements Command
{
    private ?PluginRegistry $plugins;

    /**
     * @param (Closure(): list<Step>)|null $steps   the steps to run instead of those of the website type (for tests)
     * @param PluginRegistry|null          $plugins the website types; default: the plugins of the program
     */
    public function __construct(private readonly ?Closure $steps = null, ?PluginRegistry $plugins = null)
    {
        $this->plugins = $plugins;
    }

    private function plugins(): PluginRegistry
    {
        return $this->plugins ??= PluginRegistry::load();
    }

    public function name(): string
    {
        return 'migrate';
    }

    public function summary(): string
    {
        return 'Migrate a restored site to a new domain, path or database (dry run unless --yes)';
    }

    public function usage(): string
    {
        return 'migrate <' . implode('|', $this->plugins()->ids()) . '> --root=PATH --to=DOMAIN [options]';
    }

    public function options(): array
    {
        $options = $this->commonOptions();
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

    /** @return list<OptionSpec> */
    private function commonOptions(): array
    {
        return [
            new OptionSpec('root', 'Directory of the installation', takesValue: true, valueName: 'PATH'),
            new OptionSpec('to', 'The new domain, e.g. shop.example or localhost:8290', takesValue: true, valueName: 'DOMAIN'),
            new OptionSpec('from', 'The old domain (default: what the website type finds in the installation)', takesValue: true, valueName: 'DOMAIN'),
            new OptionSpec('domain', 'Another mapping, old=new (repeatable; for several shop URLs)', takesValue: true, valueName: 'OLD=NEW', repeatable: true),
            new OptionSpec('db-host', 'Database host (default: from the installation)', takesValue: true, valueName: 'HOST'),
            new OptionSpec('db-port', 'Database port (default: from the installation)', takesValue: true, valueName: 'PORT'),
            new OptionSpec('db-name', 'Database name (default: from the installation)', takesValue: true, valueName: 'NAME'),
            new OptionSpec('db-user', 'Database user (default: from the installation)', takesValue: true, valueName: 'USER'),
            new OptionSpec('db-password-file', 'File containing the database password (or set THREADSAFE_DB_PASSWORD)', takesValue: true, valueName: 'FILE'),
            new OptionSpec('db-driver', 'PHP interface to the database: auto (default: pdo_mysql, else mysqli), pdo or mysqli', takesValue: true, valueName: 'DRIVER'),
            new OptionSpec('prefix', 'Table prefix (default: from the installation)', takesValue: true, valueName: 'PREFIX'),
            new OptionSpec('server-path', 'Directory of the installation as the web server sees it, if it differs from --root', takesValue: true, valueName: 'PATH'),
            new OptionSpec('skip-tables', 'Extra tables the content replacement skips, comma separated', takesValue: true, valueName: 'LIST'),
            new OptionSpec('replace-bare', 'Also replace bare domain names in content (not only URLs)'),
            new OptionSpec('chunk-size', 'Rows per chunk in the content replacement (default 2000)', takesValue: true, valueName: 'N'),
            new OptionSpec('self-test-url', 'Self-test: address the web server answers on if not the site domain, e.g. http://web (default: the site domain)', takesValue: true, valueName: 'URL'),
            new OptionSpec('self-test-insecure', 'Self-test: do not verify TLS certificates'),
            new OptionSpec('no-self-test', 'Do not check the migrated site over HTTP at the end'),
            new OptionSpec('request', 'JSON file with the same settings (command line options win)', takesValue: true, valueName: 'FILE'),
            new OptionSpec('journal-dir', 'Where the journal goes (default: <root>/var/threadsafe-journal)', takesValue: true, valueName: 'DIR'),
            new OptionSpec('report', 'Write the report as JSON to this file', takesValue: true, valueName: 'FILE'),
            new OptionSpec('dry-run', 'Only plan and report (this is the default)'),
            new OptionSpec('yes', 'Really apply the changes'),
        ];
    }

    public function run(Input $input, Output $output): ExitCode
    {
        $type = $this->websiteType($input);
        if (count($input->arguments()) > 1) {
            throw new UsageError('Unexpected argument "' . $input->arguments()[1] . '".');
        }
        if ($input->has('dry-run') && $input->has('yes')) {
            throw new UsageError('--dry-run and --yes contradict each other.');
        }
        $this->rejectForeignOptions($input, $type);

        $builder = $type->requestBuilder();
        try {
            $request = $builder->build($this->settings($input, $type));
        } catch (InvalidArgumentException $e) {
            throw new UsageError($e->getMessage(), 0, $e);
        }
        foreach ($builder->notes() as $note) {
            $output->writeln('Note: ' . $note);
        }

        $apply = $input->has('yes');
        $journalDirectory = $input->value('journal-dir') ?? $request->root . '/var/threadsafe-journal';
        $journal = $apply ? new LazyJournal(static fn (): Journal => new FileJournal($journalDirectory)) : new NullJournal();
        $context = new Context($request, $journal, new OutputLogger($output), !$apply);

        $steps = $this->steps !== null ? ($this->steps)() : $type->steps();
        $result = (new Runner($steps))->run($context, $apply);

        if ($result->failure instanceof GuardFailed) {
            throw $result->failure;
        }

        (new TextReportRenderer())->render($result->report, $output);
        $this->writeReport($input->value('report'), $result->report->toArray());

        if ($result->failure !== null) {
            throw $result->failure;
        }
        if (!$apply) {
            $output->writeln($result->report->plannedChanges() > 0
                ? 'Nothing was changed. Run the same command with --yes to apply these changes.'
                : 'Nothing to do: the installation is already in the requested state.');
        }

        return $result->exitCode;
    }

    private function websiteType(Input $input): WebsiteType
    {
        $id = $input->argument(0);
        $available = implode(', ', $this->plugins()->ids());
        if ($id === null) {
            throw new UsageError("Give the website type as the first argument (available: $available).");
        }
        if (!$this->plugins()->has($id)) {
            throw new UsageError("Unknown website type \"$id\" (available: $available).");
        }

        try {
            return $this->plugins()->get($id);
        } catch (PluginError $e) {
            throw new UsageError($e->getMessage(), 0, $e);
        }
    }

    /** An option of another website type is a mistake, not something to ignore quietly. */
    private function rejectForeignOptions(Input $input, WebsiteType $type): void
    {
        $own = array_flip(array_map(static fn (OptionSpec $o): string => $o->name, $type->options()));
        foreach ($this->plugins()->all() as $other) {
            if ($other->id() === $type->id()) {
                continue;
            }
            foreach ($other->options() as $option) {
                if ($input->has($option->name) && !isset($own[$option->name])) {
                    throw new UsageError("--{$option->name} is an option of the website type {$other->id()}, not of {$type->id()}.");
                }
            }
        }
    }

    /**
     * The request file's settings, overridden by the command line options.
     *
     * @return array<string, mixed>
     */
    private function settings(Input $input, WebsiteType $type): array
    {
        $settings = [];
        if (($file = $input->value('request')) !== null) {
            $content = is_file($file) ? file_get_contents($file) : false;
            if ($content === false) {
                throw new UsageError("The request file \"$file\" cannot be read.");
            }
            try {
                $decoded = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new UsageError('The request file is not valid JSON: ' . $e->getMessage(), 0, $e);
            }
            if (!is_array($decoded)) {
                throw new UsageError('The request file must contain a JSON object.');
            }
            $settings = $decoded;
        }

        $flags = ['root' => 'root', 'from' => 'from', 'to' => 'to', 'server-path' => 'serverPath', 'self-test-url' => 'selfTestUrl'];
        foreach ($flags as $option => $key) {
            if ($input->has($option)) {
                $settings[$key] = $input->value($option);
            }
        }
        if ($input->has('domain')) {
            $existing = $settings['domains'] ?? [];
            $settings['domains'] = [...(is_array($existing) ? $existing : []), ...$input->values('domain')];
        }

        $database = is_array($settings['database'] ?? null) ? $settings['database'] : [];
        foreach (['db-host' => 'host', 'db-port' => 'port', 'db-name' => 'name', 'db-user' => 'user', 'db-password-file' => 'passwordFile', 'db-driver' => 'driver', 'prefix' => 'prefix'] as $option => $key) {
            if ($input->has($option)) {
                $database[$key] = $input->value($option);
                if ($key === 'passwordFile') {
                    unset($database['password']);
                }
            }
        }
        if ($database !== []) {
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

    /**
     * @param array<string, mixed> $report
     */
    private function writeReport(?string $path, array $report): void
    {
        if ($path === null) {
            return;
        }
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        if (@file_put_contents($path, $json . "\n") === false) {
            throw new UsageError("The report file \"$path\" cannot be written.");
        }
        @chmod($path, 0600);
    }
}
