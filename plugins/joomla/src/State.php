<?php

declare(strict_types=1);

namespace ThreadSafe\Joomla;

use RuntimeException;
use ThreadSafe\Core\Context;
use ThreadSafe\Database\Connection;
use ThreadSafe\Generic\State as GenericState;
use ThreadSafe\Migration\MigrationRequest;

/**
 * Typed access to what the steps of the Joomla type share through the {@see Context}: the request, the state that `ReadJoomlaState`
 * found and the open database connection. The connection is also given to the steps of the generic type that this type uses
 * (the replacement of the old address in the content), which look for it where they put it themselves.
 */
final class State
{
    private const string SOURCE = 'joomla.source';

    private const string CONNECTION = 'joomla.connection';

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
        GenericState::store($context, $connection);
    }

    public static function source(Context $context): SourceState
    {
        $source = $context->get(self::SOURCE);
        if (!$source instanceof SourceState) {
            throw new RuntimeException('The source state is invalid.');
        }

        return $source;
    }

    public static function connection(Context $context): Connection
    {
        $connection = $context->get(self::CONNECTION);
        if (!$connection instanceof Connection) {
            throw new RuntimeException('The database connection is invalid.');
        }

        return $connection;
    }

    private function __construct()
    {
    }
}
