<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9\Modules;

use InvalidArgumentException;
use JsonException;
use ThreadSafe\Database\Connection;
use ThreadSafe\PrestaShop9\DevMode\DevRule;

/**
 * The module rules in effect: the ones shipped in `resources/prestashop9/modules/` and the user's in a rules directory
 * (`--rules-dir`, default `data/rules/` next to the program, if it exists). A user rule replaces a shipped rule of the
 * same module as a whole.
 *
 * A rule file is `<module>.json`, plain data (see `resources/prestashop9/module-rule.schema.json`); it is validated
 * strictly, because it decides which files are deleted and which values are rewritten. The steps work with the rules
 * of the modules that are installed in the shop being migrated ({@see forInstalled()}).
 */
final readonly class ModuleRules
{
    /** Keys a rule file may have. */
    public const array KEYS = ['module', 'description', 'domainColumns', 'configuration', 'skipTables', 'files', 'dev'];

    private const string IDENTIFIER = '/^[A-Za-z0-9_]+$/';

    /** Where `delete` rules may reach (besides the module's own directory). */
    private const array DELETABLE_PREFIXES = ['var/cache/', 'var/logs/', 'cache/', 'img/tmp/'];

    /**
     * @param array<string, ModuleRule> $rules      by module name
     * @param list<string>              $knownNames every module that has a rule, also those left out by {@see forInstalled()}
     */
    private function __construct(private array $rules, private ?array $knownNames = null)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param string|null $userDirectory the user's rules; null = `data/rules` next to the program, if it exists
     * @param string|null $shippedDirectory for tests; default: the shipped rules
     *
     * @throws InvalidArgumentException a rule file is unreadable or invalid, or the directory does not exist
     */
    public static function load(?string $userDirectory = null, ?string $shippedDirectory = null): self
    {
        $rules = [];
        $shipped = $shippedDirectory ?? dirname(__DIR__, 3) . '/resources/prestashop9/modules';
        foreach (self::jsonFiles($shipped) as $file) {
            $rule = self::parse($file);
            $rules[$rule->module] = $rule;
        }

        if ($userDirectory === null) {
            $default = dirname(__DIR__, 3) . '/data/rules';
            $userDirectory = is_dir($default) ? $default : null;
        } elseif (!is_dir($userDirectory)) {
            throw new InvalidArgumentException("The rules directory \"$userDirectory\" does not exist.");
        }
        if ($userDirectory !== null) {
            foreach (self::jsonFiles($userDirectory) as $file) {
                $rule = self::parse($file);
                $rules[$rule->module] = $rule;
            }
        }
        ksort($rules);

        return new self($rules);
    }

    /**
     * The rules of the modules that are installed in the shop (rows of the `module` table).
     */
    public function forInstalled(Connection $db): self
    {
        $table = $db->table('module');
        if ($this->rules === [] || !$db->tableExists($table)) {
            return self::none();
        }

        $installed = [];
        foreach ($db->select('SELECT name FROM ' . $db->quote($table)) as $row) {
            $installed[(string) $row['name']] = true;
        }

        return new self(array_intersect_key($this->rules, $installed), $this->knownNames());
    }

    /** @return list<ModuleRule> */
    public function all(): array
    {
        return array_values($this->rules);
    }

    /**
     * Every module that has a rule, installed or not (what `--dev-skip=module:<name>` may name).
     *
     * @return list<string>
     */
    public function knownNames(): array
    {
        return $this->knownNames ?? array_keys($this->rules);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->rules);
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }

    /** @return list<string> tables (without prefix) the content replacement skips */
    public function skipTables(): array
    {
        $tables = [];
        foreach ($this->rules as $rule) {
            foreach ($rule->skipTables as $table) {
                $tables[$table] = true;
            }
        }

        return array_keys($tables);
    }

    /**
     * @return array<string, DomainColumn> the columns of a table (without prefix) the rules describe, by column name
     */
    public function columnsOf(string $table): array
    {
        $columns = [];
        foreach ($this->rules as $rule) {
            foreach ($rule->domainColumns as $column) {
                if ($column->table === $table) {
                    $columns[$column->column] = $column;
                }
            }
        }

        return $columns;
    }

    /**
     * @return list<string> tables (without prefix) that at least one rule says something about
     */
    public function knownTables(): array
    {
        $tables = [];
        foreach ($this->rules as $rule) {
            foreach ($rule->domainColumns as $column) {
                $tables[$column->table] = true;
            }
            foreach ($rule->skipTables as $table) {
                $tables[$table] = true;
            }
        }

        return array_keys($tables);
    }

    /**
     * @return list<string> configuration keys with the given `migrate` setting
     */
    public function configurationKeys(string $migrate): array
    {
        $keys = [];
        foreach ($this->rules as $rule) {
            foreach ($rule->configuration as $entry) {
                if ($entry->migrate === $migrate) {
                    $keys[$entry->key] = true;
                }
            }
        }

        return array_keys($keys);
    }

    /**
     * @return list<FileRule> the file rules with the given action
     */
    public function files(string $action): array
    {
        $files = [];
        foreach ($this->rules as $rule) {
            foreach ($rule->files as $file) {
                if ($file->action === $action) {
                    $files[] = $file;
                }
            }
        }

        return $files;
    }

    /**
     * @return list<DevRule> the dev mode rules of the modules, one per module that has dev actions
     */
    public function devRules(): array
    {
        $rules = [];
        foreach ($this->rules as $rule) {
            $dev = $rule->devRule();
            if ($dev !== null) {
                $rules[] = $dev;
            }
        }

        return $rules;
    }

    /**
     * @return list<string>
     */
    private static function jsonFiles(string $directory): array
    {
        $files = glob(rtrim($directory, '/\\') . '/*.json') ?: [];
        sort($files);

        return $files;
    }

    private static function parse(string $file): ModuleRule
    {
        $content = file_get_contents($file);
        if ($content === false) {
            throw new InvalidArgumentException("The module rule \"$file\" cannot be read.");
        }
        try {
            $data = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException("The module rule \"$file\" is not valid JSON: " . $e->getMessage(), 0, $e);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException("The module rule \"$file\" must be a JSON object.");
        }

        return self::build($data, $file);
    }

    /**
     * @param array<mixed> $data
     */
    private static function build(array $data, string $file): ModuleRule
    {
        $fail = static fn (string $message): InvalidArgumentException => new InvalidArgumentException("Module rule \"$file\": $message");

        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw $fail($key === 'handler' ? 'handlers (PHP code) are not supported; a rule is data only.' : "unknown key \"$key\".");
            }
        }
        $module = $data['module'] ?? null;
        if (!is_string($module) || preg_match(self::IDENTIFIER, $module) !== 1) {
            throw $fail('"module" must be the module name (letters, digits, underscore).');
        }
        if (basename($file, '.json') !== $module) {
            throw $fail("the file must be named \"$module.json\".");
        }

        $domainColumns = [];
        foreach (self::list($data, 'domainColumns', $fail) as $entry) {
            $entry = self::object($entry, ['table', 'column', 'form', 'bare'], 'domainColumns', $fail);
            $form = $entry['form'] ?? null;
            if ($form !== null && !in_array($form, DomainColumn::FORMS, true)) {
                throw $fail('"form" must be ' . implode(', ', DomainColumn::FORMS) . '.');
            }
            $bare = $entry['bare'] ?? false;
            if (!is_bool($bare)) {
                throw $fail('"bare" must be true or false.');
            }
            $domainColumns[] = new DomainColumn(self::identifier($entry, 'table', $fail), self::identifier($entry, 'column', $fail), $form, $bare);
        }

        $configuration = [];
        foreach (self::list($data, 'configuration', $fail) as $entry) {
            $entry = self::object($entry, ['key', 'migrate', 'dev'], 'configuration', $fail);
            $migrate = $entry['migrate'] ?? 'replace';
            $dev = $entry['dev'] ?? 'keep';
            if (!in_array($migrate, ConfigurationRule::MIGRATE, true) || !in_array($dev, ConfigurationRule::DEV, true)) {
                throw $fail('configuration "migrate" must be ' . implode(', ', ConfigurationRule::MIGRATE) . ' and "dev" ' . implode(', ', ConfigurationRule::DEV) . '.');
            }
            $configuration[] = new ConfigurationRule(self::identifier($entry, 'key', $fail), $migrate, $dev);
        }

        $skipTables = [];
        foreach (self::list($data, 'skipTables', $fail) as $table) {
            if (!is_string($table) || preg_match(self::IDENTIFIER, $table) !== 1) {
                throw $fail('"skipTables" must be a list of table names without prefix.');
            }
            $skipTables[] = $table;
        }

        $files = [];
        foreach (self::list($data, 'files', $fail) as $entry) {
            $entry = self::object($entry, ['glob', 'action'], 'files', $fail);
            $glob = $entry['glob'] ?? null;
            $action = $entry['action'] ?? null;
            if (!is_string($glob) || !in_array($action, [FileRule::DELETE, FileRule::REPLACE], true)) {
                throw $fail('a file rule needs "glob" and an "action" of delete or replace.');
            }
            self::checkGlob($glob, $action, $module, $fail);
            $files[] = new FileRule($glob, $action);
        }

        $deactivate = false;
        $devEmpty = [];
        $dev = $data['dev'] ?? [];
        if (!is_array($dev)) {
            throw $fail('"dev" must be an object.');
        }
        $dev = self::object($dev, ['deactivate', 'emptyTables'], 'dev', $fail);
        if (isset($dev['deactivate'])) {
            if (!is_bool($dev['deactivate'])) {
                throw $fail('"dev.deactivate" must be true or false.');
            }
            $deactivate = $dev['deactivate'];
        }
        foreach (self::list($dev, 'emptyTables', $fail) as $table) {
            if (!is_string($table) || preg_match(self::IDENTIFIER, $table) !== 1) {
                throw $fail('"dev.emptyTables" must be a list of table names without prefix.');
            }
            $devEmpty[] = $table;
        }

        return new ModuleRule(
            $module,
            is_string($data['description'] ?? null) ? $data['description'] : '',
            $domainColumns,
            $configuration,
            $skipTables,
            $files,
            $deactivate,
            $devEmpty,
            $file,
        );
    }

    /**
     * @param array<mixed>                               $data
     * @param callable(string): InvalidArgumentException $fail
     *
     * @return list<mixed>
     */
    private static function list(array $data, string $key, callable $fail): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw $fail("\"$key\" must be a list.");
        }

        return $value;
    }

    /**
     * @param list<string>                               $allowed
     * @param callable(string): InvalidArgumentException $fail
     *
     * @return array<string, mixed>
     */
    private static function object(mixed $value, array $allowed, string $where, callable $fail): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw $fail("an entry of \"$where\" must be an object.");
        }
        foreach (array_keys($value) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw $fail("\"$where\" has an unknown key \"$key\".");
            }
        }

        return $value;
    }

    /**
     * @param array<string, mixed>                       $entry
     * @param callable(string): InvalidArgumentException $fail
     */
    private static function identifier(array $entry, string $key, callable $fail): string
    {
        $value = $entry[$key] ?? null;
        if (!is_string($value) || preg_match(self::IDENTIFIER, $value) !== 1) {
            throw $fail("\"$key\" must be a name of letters, digits and underscores.");
        }

        return $value;
    }

    /**
     * A glob stays inside the shop, and `delete` only reaches places that are rebuilt anyway.
     *
     * @param callable(string): InvalidArgumentException $fail
     */
    private static function checkGlob(string $glob, string $action, string $module, callable $fail): void
    {
        if ($glob === '' || preg_match('~^[A-Za-z0-9_./*?\[\]-]+$~', $glob) !== 1 || str_starts_with($glob, '/') || in_array('..', explode('/', $glob), true)) {
            throw $fail("the glob \"$glob\" must be relative to the shop root and contain only letters, digits and _ . / * ? [ ] -.");
        }
        if ($action !== FileRule::DELETE) {
            return;
        }
        foreach ([...self::DELETABLE_PREFIXES, "modules/$module/"] as $prefix) {
            if (str_starts_with($glob, $prefix) && strlen($glob) > strlen($prefix)) {
                return;
            }
        }
        throw $fail("a file may only be deleted below modules/$module/, " . implode(', ', self::DELETABLE_PREFIXES) . " (glob \"$glob\").");
    }
}
