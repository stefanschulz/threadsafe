<?php

declare(strict_types=1);

namespace ThreadSafe\WordPress\Steps;

use ThreadSafe\Core\Context;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\HasFindings;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\ConnectionFailed;
use ThreadSafe\WordPress\Config\WordPressConfigFile;
use ThreadSafe\WordPress\Exception\InvalidConfigFile;
use ThreadSafe\WordPress\Installation;
use ThreadSafe\WordPress\SourceState;
use ThreadSafe\WordPress\State;

/**
 * Looks at the installation and the database before anything is planned, and refuses to continue (exit code 2) if this is not what the
 * request expects: not a WordPress 6 or later, a configuration that cannot be read, a multisite, a database that cannot be reached or whose
 * tables do not have the prefix, or a new base path for a site that does not live at the root of its domain. Changes nothing.
 */
final class ReadWordPressState implements Step, HasFindings
{
    public const string MULTISITE = 'This WordPress is a multisite network, which ThreadSafe does not restore yet. Back it up as "Other website" (the type "generic") for a plain backup of the files and the database.';

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
            ?? throw new GuardFailed("\"$root\" does not look like a WordPress installation (wp-includes/version.php not found).");
        if (!Installation::isSupported($version)) {
            throw new GuardFailed(sprintf('Only WordPress %s and later is supported, found version %s.', Installation::MINIMUM_VERSION, $version));
        }
        $found = Installation::configuration($root)
            ?? throw new GuardFailed("\"$root\" has no wp-config.php (in the directory, or in the one above it): WordPress is not installed there.");
        try {
            $config = WordPressConfigFile::fromPath($found['path']);
            if ($config->isMultisite()) {
                throw new GuardFailed(self::MULTISITE);
            }
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
        $options = $connection->table('options');
        if (!$connection->tableExists($options)) {
            throw new GuardFailed("Table \"$options\" does not exist: wrong table prefix, or this is not a WordPress database.");
        }
        if ($connection->tableExists($connection->table('blogs'))) {
            throw new GuardFailed(self::MULTISITE);
        }
        $siteUrl = (string) $connection->scalar('SELECT option_value FROM ' . $connection->quote($options) . " WHERE option_name = 'siteurl'");
        $homeUrl = (string) $connection->scalar('SELECT option_value FROM ' . $connection->quote($options) . " WHERE option_name = 'home'");
        self::checkBasePath($request->physicalUri, $homeUrl);

        $source = new SourceState(
            $root,
            $version,
            $configured,
            $database,
            $found['path'],
            $siteUrl,
            $homeUrl,
            $config->has('WP_HOME'),
            $config->has('WP_SITEURL'),
            $connection->serverVersion(),
        );
        State::store($context, $source, $connection);
        $plan->notice(Notice::info(sprintf(
            'WordPress %s, database "%s" (prefix "%s") on %s (%s); the address is %s.',
            $version,
            $database->name,
            $database->prefix,
            $connection->server->describe(),
            $connection->driverName(),
            $homeUrl === '' ? 'not set' : $homeUrl,
        )));
        foreach (array_diff_key($config->notUnderstood(), ['ABSPATH' => true]) as $name => $by) {
            $plan->notice(Notice::warning("The setting \"$name\" of wp-config.php is not a plain value" . ($by === null ? '' : " (computed by $by())") . '; it is left as it is.', $name));
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

    /**
     * A new base path only works for a site at the root of its old domain: the replacement writes the path behind the new host and cannot
     * take one away or change one.
     *
     * @throws GuardFailed
     */
    public static function checkBasePath(?string $newBase, string $homeUrl): void
    {
        if ($newBase === null) {
            return;
        }
        $path = trim((string) (parse_url($homeUrl, PHP_URL_PATH) ?? ''), '/');
        if ($path !== '') {
            throw new GuardFailed(sprintf('The site lives in the directory "/%s/" of its domain; ThreadSafe can move a site into a directory only from the root of a domain, so no base path may be given for it (the site keeps its directory).', $path));
        }
    }
}
