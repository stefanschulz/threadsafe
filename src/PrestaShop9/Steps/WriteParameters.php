<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9\Steps;

use ThreadSafe\Core\Change;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\PrestaShop9\ParametersFile;
use ThreadSafe\PrestaShop9\State;

/**
 * Points `app/config/parameters.php` to the database the request names. The file is edited surgically: only the
 * `database_*` values change; `secret`, the cookie keys and everything else stay byte-identical.
 *
 * Without a database in the request the file already describes the right database and the plan is empty.
 */
final class WriteParameters implements Step
{
    private const string FILE = 'app/config/parameters.php';

    public function id(): string
    {
        return 'write-parameters';
    }

    public function title(): string
    {
        return 'Write the database settings to parameters.php';
    }

    public function plan(Context $context): Plan
    {
        $source = State::source($context);
        $plan = new Plan();
        $old = $source->configuredDatabase;
        $new = $source->database;

        $values = [
            ['database_host', $old->host, $new->host, false],
            ['database_port', (string) $old->port, (string) $new->port, false],
            ['database_name', $old->name, $new->name, false],
            ['database_user', $old->user, $new->user, false],
            ['database_password', $old->password, $new->password, true],
            ['database_prefix', $old->prefix, $new->prefix, false],
        ];
        foreach ($values as [$key, $before, $after, $secret]) {
            if ($before !== $after) {
                $plan->add(Change::update('file ' . self::FILE . " $key", 'point the shop to the new database', $before, $after, secret: $secret));
            }
        }

        return $plan;
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $source = State::source($context);
        $path = $source->root . '/' . self::FILE;

        $current = ParametersFile::fromPath($path);
        $updated = $current->withDatabase($source->database);
        if ($updated->toString() === $current->toString()) {
            return new StepResult(0);
        }

        $backup = $context->journal->backupFile($path);
        $updated->writeTo($path);
        $context->journal->file($path, 'update', $backup);

        return new StepResult($plan->changeCount());
    }
}
