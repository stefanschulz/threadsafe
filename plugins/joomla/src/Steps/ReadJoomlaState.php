<?php

declare(strict_types=1);

namespace ThreadSafe\Joomla\Steps;

use ThreadSafe\Core\Context;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\HasFindings;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\ConnectionFailed;
use ThreadSafe\Joomla\Config\JoomlaConfigFile;
use ThreadSafe\Joomla\Exception\InvalidConfigFile;
use ThreadSafe\Joomla\Installation;
use ThreadSafe\Joomla\SourceState;
use ThreadSafe\Joomla\State;

/**
 * Looks at the installation and the database before anything is planned, and refuses to continue (exit code 2) if this is not what the
 * request expects: not a Joomla 5 or 6, a configuration that cannot be read, a database that cannot be reached or whose tables do not
 * have the prefix. Changes nothing.
 */
final class ReadJoomlaState implements Step, HasFindings
{
    public function id(): string
    {
        return 'read-source-state';
    }

    public function title(): string
    {
        return 'Read the installation to be migrated';
    }

    public function plan(Context $context): Plan
    {
        $request = State::request($context);
        $plan = new Plan();

        $root = rtrim($request->root, '/\\');
        if (!is_dir($root)) {
            throw new GuardFailed("The site root \"$root\" is not a directory.");
        }
        $version = Installation::version($root)
            ?? throw new GuardFailed("\"$root\" does not look like a Joomla 5 or 6 installation (libraries/src/Version.php not found).");
        if (!in_array((int) explode('.', $version)[0], Installation::MAJOR_VERSIONS, true)) {
            throw new GuardFailed("Only Joomla 5 and 6 are supported, found version $version.");
        }
        if (!Installation::isInstalled($root)) {
            throw new GuardFailed("\"$root\" has no configuration.php: Joomla is not installed there.");
        }

        try {
            $config = JoomlaConfigFile::fromPath(Installation::configurationPath($root));
            $configured = $config->database();
        } catch (InvalidConfigFile $e) {
            throw new GuardFailed('Cannot read the database settings of the site: ' . $e->getMessage(), 0, $e);
        }
        $database = $request->database ?? $configured;

        try {
            $connection = Connection::open($database);
        } catch (ConnectionFailed $e) {
            throw new GuardFailed($e->getMessage(), 0, $e);
        }
        if (($problem = $connection->server->problem()) !== null) {
            throw new GuardFailed($problem);
        }
        $table = $connection->table('extensions');
        if (!$connection->tableExists($table)) {
            throw new GuardFailed("Table \"$table\" does not exist: wrong table prefix, or this is not a Joomla database.");
        }

        $source = new SourceState(
            $root,
            $version,
            $configured,
            $database,
            self::string($config->get('dbtype')),
            self::string($config->get('live_site')),
            self::string($config->get('log_path')),
            self::string($config->get('tmp_path')),
            $connection->serverVersion(),
        );
        State::store($context, $source, $connection);

        $plan->notice(Notice::info(sprintf(
            'Joomla %s, database "%s" (prefix "%s") on %s (%s).',
            $version,
            $database->name,
            $database->prefix,
            $connection->server->describe(),
            $connection->driverName(),
        )));
        if (($warning = Installation::databaseProblem($source->major(), $connection->server)) !== null) {
            $plan->notice(Notice::warning($warning));
        }
        foreach ($config->notUnderstood() as $name) {
            $plan->notice(Notice::warning("The setting \"$name\" of configuration.php is not a plain value; it is left as it is.", $name));
        }

        return $plan;
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        return new StepResult(0);
    }

    public function exportFindings(Context $context): array
    {
        return State::source($context)->toArray();
    }

    public function importFindings(Context $context, array $findings): void
    {
        $source = SourceState::fromArray($findings);
        State::store($context, $source, Connection::open($source->database));
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
