<?php

declare(strict_types=1);

namespace ThreadSafe\Generic\Steps;

use ThreadSafe\Core\Context;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\ConnectionFailed;
use ThreadSafe\Generic\State;

/**
 * Looks at the site before anything is planned: the database, if one was given, must be reachable and a supported
 * server. A generic site has no application to recognise, so there is nothing else to check. Changes nothing.
 */
final class ReadGenericState implements Step
{
    public function id(): string
    {
        return 'read-source-state';
    }

    public function title(): string
    {
        return 'Read the site to be migrated';
    }

    public function plan(Context $context): Plan
    {
        $request = State::request($context);
        $plan = new Plan();

        $connection = null;
        if ($request->database !== null) {
            try {
                $connection = Connection::open($request->database);
            } catch (ConnectionFailed $e) {
                throw new GuardFailed($e->getMessage(), 0, $e);
            }
            if (($problem = $connection->server->problem()) !== null) {
                throw new GuardFailed($problem);
            }
            $plan->notice(Notice::info(sprintf(
                'Database "%s" on %s (%s).',
                $request->database->name,
                $connection->server->describe(),
                $connection->driverName(),
            )));
        } else {
            $plan->notice(Notice::info('No database given (--db-host, --db-name, --db-user ...): only the checks at the end can run.'));
        }
        State::store($context, $connection);

        if ($request->isRestoreInPlace()) {
            $plan->notice(Notice::info('No domain mapping given (--from and --to): the content is not changed.'));
        }

        return $plan;
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        return new StepResult(0);
    }
}
