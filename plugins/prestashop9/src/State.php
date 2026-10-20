<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9;

use InvalidArgumentException;
use RuntimeException;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Database\Connection;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\PrestaShop9\Modules\ModuleRules;

/**
 * Typed access to what the steps share through the {@see Context}: the request, the discovered source state and the
 * open database connection. The latter two are stored by the ReadSourceState step.
 */
final class State
{
    private const string SOURCE = 'prestashop9.source';

    private const string CONNECTION = 'prestashop9.connection';

    private const string MODULE_RULES = 'prestashop9.moduleRules';

    public static function request(Context $context): MigrationRequest
    {
        $request = $context->request;
        if (!$request instanceof MigrationRequest) {
            throw new RuntimeException('The context does not carry a MigrationRequest.');
        }

        return $request;
    }

    public static function store(Context $context, SourceState $source, Connection $connection): void
    {
        $context->set(self::SOURCE, $source);
        $context->set(self::CONNECTION, $connection);
    }

    public static function source(Context $context): SourceState
    {
        $source = $context->get(self::SOURCE);
        if (!$source instanceof SourceState) {
            throw new RuntimeException('The source state is invalid.');
        }

        return $source;
    }

    /**
     * The module rules of the modules installed in the shop. Loaded on first use; a broken rule file stops the run
     * before anything is changed (it is first used by ReadSourceState).
     *
     * @throws GuardFailed a rule file is invalid
     */
    public static function moduleRules(Context $context): ModuleRules
    {
        if (!$context->has(self::MODULE_RULES)) {
            try {
                $rules = ModuleRules::load(self::request($context)->rulesDir)->forInstalled(self::connection($context));
            } catch (InvalidArgumentException $e) {
                throw new GuardFailed($e->getMessage(), 0, $e);
            }
            $context->set(self::MODULE_RULES, $rules);
        }
        $rules = $context->get(self::MODULE_RULES);
        if (!$rules instanceof ModuleRules) {
            throw new RuntimeException('The module rules are invalid.');
        }

        return $rules;
    }

    public static function connection(Context $context): Connection
    {
        $connection = $context->get(self::CONNECTION);
        if (!$connection instanceof Connection) {
            throw new RuntimeException('The database connection is invalid.');
        }

        return $connection;
    }
}
