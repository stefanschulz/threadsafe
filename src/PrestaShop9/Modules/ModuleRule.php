<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9\Modules;

use ThreadSafe\PrestaShop9\DevMode\DevRule;

/**
 * What ThreadSafe knows about one module (docs/PHASE1.md 3.8). Immutable; built by {@see ModuleRules}.
 */
final readonly class ModuleRule
{
    /**
     * @param list<DomainColumn>      $domainColumns
     * @param list<ConfigurationRule> $configuration
     * @param list<string>            $skipTables       tables (without prefix) the content replacement leaves alone
     * @param list<FileRule>          $files
     * @param list<string>            $devEmptyTables   tables (without prefix) emptied in dev mode
     */
    public function __construct(
        public string $module,
        public string $description = '',
        public array $domainColumns = [],
        public array $configuration = [],
        public array $skipTables = [],
        public array $files = [],
        public bool $devDeactivate = false,
        public array $devEmptyTables = [],
        public string $origin = '',
    ) {
    }

    /** Id under which the dev mode rule of this module can be switched off: `--dev-skip=module:<name>`. */
    public function devRuleId(): string
    {
        return 'module:' . $this->module;
    }

    /** The dev mode actions of this rule as a {@see DevRule}, or null if it has none. */
    public function devRule(): ?DevRule
    {
        $clear = [];
        $remove = [];
        foreach ($this->configuration as $entry) {
            if ($entry->dev === 'clear') {
                $clear[] = $entry->key;
            } elseif ($entry->dev === 'remove') {
                $remove[] = addcslashes($entry->key, '%_\\');
            }
        }
        if ($clear === [] && $remove === [] && $this->devEmptyTables === [] && !$this->devDeactivate) {
            return null;
        }

        return new DevRule(
            $this->devRuleId(),
            'Module ' . $this->module,
            $this->description,
            clear: $clear,
            remove: $remove,
            emptyTables: $this->devEmptyTables,
            deactivateModules: $this->devDeactivate ? [$this->module] : [],
        );
    }
}
