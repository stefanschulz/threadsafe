<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9\DevMode;

/**
 * One rule of the dev mode (see plugins/prestashop9/resources/dev-mode.json): everything that cuts one kind of live
 * connection. A rule is switched on or off as a whole.
 */
final readonly class DevRule
{
    /**
     * @param list<array{name: string, value: string, when: string|null, create: bool}> $set               configuration values to set
     * @param list<string>                                                              $clear             configuration names whose value becomes empty
     * @param list<string>                                                              $remove            LIKE patterns of configuration names whose rows are deleted
     * @param list<string>                                                              $emptyTables       tables (without prefix) to empty
     * @param list<string>                                                              $deactivateModules modules to deactivate
     * @param array{robots: string, header: string}|null                                $noindex           how to keep search engines out
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $description = '',
        public array $set = [],
        public array $clear = [],
        public array $remove = [],
        public array $emptyTables = [],
        public array $deactivateModules = [],
        public ?array $noindex = null,
    ) {
    }
}
