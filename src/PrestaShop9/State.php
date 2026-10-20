<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9;

use RuntimeException;
use ThreadSafe\Core\Context;
use ThreadSafe\Database\Connection;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\Migration\SourceState;

/**
 * Typed access to what the steps share through the {@see Context}: the request, the discovered source state and the
 * open database connection. The latter two are stored by the ReadSourceState step.
 */
final class State
{
    private const string SOURCE = 'prestashop9.source';

    private const string CONNECTION = 'prestashop9.connection';

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

    public static function connection(Context $context): Connection
    {
        $connection = $context->get(self::CONNECTION);
        if (!$connection instanceof Connection) {
            throw new RuntimeException('The database connection is invalid.');
        }

        return $connection;
    }
}
