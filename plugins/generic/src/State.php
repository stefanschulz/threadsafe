<?php

declare(strict_types=1);

namespace ThreadSafe\Generic;

use RuntimeException;
use ThreadSafe\Core\Context;
use ThreadSafe\Database\Connection;
use ThreadSafe\Migration\MigrationRequest;

/**
 * Typed access to what the steps of the generic website type share through the {@see Context}.
 */
final class State
{
    private const string CONNECTION = 'generic.connection';

    public static function request(Context $context): MigrationRequest
    {
        $request = $context->request;
        if (!$request instanceof MigrationRequest) {
            throw new RuntimeException('The context does not carry a MigrationRequest.');
        }

        return $request;
    }

    public static function store(Context $context, ?Connection $connection): void
    {
        $context->set(self::CONNECTION, $connection);
    }

    /** The database connection, or null if the site has none (or no database was given). */
    public static function connection(Context $context): ?Connection
    {
        $connection = $context->get(self::CONNECTION);
        if ($connection !== null && !$connection instanceof Connection) {
            throw new RuntimeException('The database connection is invalid.');
        }

        return $connection;
    }

    private function __construct()
    {
    }
}
