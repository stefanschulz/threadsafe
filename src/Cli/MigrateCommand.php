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
use ThreadSafe\PrestaShop9\MigrationPipeline;
use ThreadSafe\PrestaShop9\RequestBuilder;

/**
 * `threadsafe migrate prestashop9`: turns a restored copy of a PrestaShop 9 shop into a working site on a new
 * domain, path or database (or, without a new domain, into a working restore in place).
 *
 * Without --yes the command only plans and reports (dry run). With --yes it applies the plan, writes a journal
 * of everything it changes and reports what it did.
 */
final class MigrateCommand implements Command
{
    /** @var Closure(): list<Step> */
    private readonly Closure $steps;

    /**
     * @param (Closure(): list<Step>)|null $steps the steps to run; defaults to the PrestaShop 9 pipeline
     */
    public function __construct(?Closure $steps = null)
    {
        $this->steps = $steps ?? MigrationPipeline::steps(...);
    }

    public function name(): string
    {
        return 'migrate';
    }

    public function summary(): string
    {
        return 'Migrate a restored PrestaShop 9 copy to a new domain, path or database (dry run unless --yes)';
    }

    public function usage(): string
    {
        return 'migrate prestashop9 --root=PATH --to=DOMAIN [options]';
    }

    public function options(): array
    {
        return [
            new OptionSpec('root', 'Directory of the PrestaShop installation', takesValue: true, valueName: 'PATH'),
            new OptionSpec('to', 'The new domain, e.g. shop.example or localhost:8290', takesValue: true, valueName: 'DOMAIN'),
            new OptionSpec('from', 'The old domain (default: the main domain in the shop database)', takesValue: true, valueName: 'DOMAIN'),
            new OptionSpec('domain', 'Another mapping, old=new (repeatable; for several shop URLs)', takesValue: true, valueName: 'OLD=NEW', repeatable: true),
            new OptionSpec('path', 'New base path, e.g. /shop/ (default: unchanged)', takesValue: true, valueName: 'PATH'),
            new OptionSpec('ssl', 'SSL mode: none, login or all (default: unchanged)', takesValue: true, valueName: 'MODE'),
            new OptionSpec('mode', 'production (default) or dev: cut live mail, payment and cloud connections, keep search engines out', takesValue: true, valueName: 'MODE'),
            new OptionSpec('mail-catcher', 'Dev mode: SMTP server that receives all mail, HOST or HOST:PORT (default: the shop sends no mail)', takesValue: true, valueName: 'HOST[:PORT]'),
            new OptionSpec('dev-skip', 'Dev mode: rule ids to leave out, comma separated (mail, payment, accounts, tracking, noindex)', takesValue: true, valueName: 'LIST'),
            new OptionSpec('dev-rules', 'Dev mode: JSON file with more rules (format of resources/prestashop9/dev-mode.json)', takesValue: true, valueName: 'FILE'),
            new OptionSpec('db-host', 'Database host (default: parameters.php)', takesValue: true, valueName: 'HOST'),
            new OptionSpec('db-port', 'Database port (default: parameters.php)', takesValue: true, valueName: 'PORT'),
            new OptionSpec('db-name', 'Database name (default: parameters.php)', takesValue: true, valueName: 'NAME'),
            new OptionSpec('db-user', 'Database user (default: parameters.php)', takesValue: true, valueName: 'USER'),
            new OptionSpec('db-password-file', 'File containing the database password (or set THREADSAFE_DB_PASSWORD)', takesValue: true, valueName: 'FILE'),
            new OptionSpec('prefix', 'Table prefix (default: parameters.php)', takesValue: true, valueName: 'PREFIX'),
            new OptionSpec('server-path', 'Shop directory as the web server sees it, if it differs from --root', takesValue: true, valueName: 'PATH'),
            new OptionSpec('skip-tables', 'Extra tables (without prefix) the content replacement skips, comma separated', takesValue: true, valueName: 'LIST'),
            new OptionSpec('replace-bare', 'Also replace bare domain names in content (not only URLs)'),
            new OptionSpec('chunk-size', 'Rows per chunk in the content replacement (default 2000)', takesValue: true, valueName: 'N'),
            new OptionSpec('rules-dir', 'Directory with your module rules, one <module>.json each (default: data/rules next to the program)', takesValue: true, valueName: 'DIR'),
            new OptionSpec('self-test-url', 'Self-test: address the web server answers on if not the shop domain, e.g. http://web (default: the shop domain)', takesValue: true, valueName: 'URL'),
            new OptionSpec('self-test-insecure', 'Self-test: do not verify TLS certificates'),
            new OptionSpec('no-self-test', 'Do not check the migrated shop over HTTP at the end'),
            new OptionSpec('no-finalize', 'Do not let PrestaShop clear its caches itself at the end'),
            new OptionSpec('request', 'JSON file with the same settings (command line options win)', takesValue: true, valueName: 'FILE'),
            new OptionSpec('journal-dir', 'Where the journal goes (default: <root>/var/threadsafe-journal)', takesValue: true, valueName: 'DIR'),
            new OptionSpec('report', 'Write the report as JSON to this file', takesValue: true, valueName: 'FILE'),
            new OptionSpec('dry-run', 'Only plan and report (this is the default)'),
            new OptionSpec('yes', 'Really apply the changes'),
        ];
    }

    public function run(Input $input, Output $output): ExitCode
    {
        if ($input->argument(0) !== 'prestashop9') {
            throw new UsageError('Give the website type as the first argument: "prestashop9" is the only one available.');
        }
        if (count($input->arguments()) > 1) {
            throw new UsageError('Unexpected argument "' . $input->arguments()[1] . '".');
        }
        if ($input->has('dry-run') && $input->has('yes')) {
            throw new UsageError('--dry-run and --yes contradict each other.');
        }

        $builder = new RequestBuilder();
        try {
            $request = $builder->build($this->settings($input));
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

        $result = (new Runner(($this->steps)()))->run($context, $apply);

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

    /**
     * The request file's settings, overridden by the command line options.
     *
     * @return array<string, mixed>
     */
    private function settings(Input $input): array
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

        $flags = ['root' => 'root', 'from' => 'from', 'to' => 'to', 'path' => 'path', 'ssl' => 'ssl', 'mode' => 'mode', 'server-path' => 'serverPath', 'mail-catcher' => 'mailCatcher', 'dev-rules' => 'devRules', 'rules-dir' => 'rulesDir', 'self-test-url' => 'selfTestUrl'];
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
        foreach (['db-host' => 'host', 'db-port' => 'port', 'db-name' => 'name', 'db-user' => 'user', 'db-password-file' => 'passwordFile', 'prefix' => 'prefix'] as $option => $key) {
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
        if ($input->has('dev-skip')) {
            $settings['devSkip'] = array_values(array_filter(array_map('trim', explode(',', (string) $input->value('dev-skip')))));
        }
        foreach (['no-self-test' => 'selfTest', 'no-finalize' => 'finalize'] as $option => $key) {
            if ($input->has($option)) {
                $settings[$key] = false;
            }
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

        return $settings;
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
