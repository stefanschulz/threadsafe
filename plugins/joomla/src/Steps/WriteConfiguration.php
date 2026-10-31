<?php

declare(strict_types=1);

namespace ThreadSafe\Joomla\Steps;

use ThreadSafe\Core\Change;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Database\DatabaseSettings;
use ThreadSafe\Joomla\Config\JoomlaConfigFile;
use ThreadSafe\Joomla\Installation;
use ThreadSafe\Joomla\State;
use ThreadSafe\Migration\MigrationRequest;

/**
 * Adapts `configuration.php` to the host the site now runs on, changing only the values that must change (`secret`, the mail settings and
 * the site's own properties stay byte for byte):
 *
 * - the database (host, port, user, password, name, prefix) if the request names another one;
 * - `dbtype`, `mysqli` or `pdomysql`: the one that `--db-driver` names, else by what this PHP has (a host often has only one of them, and
 *   the PHP of a command line is not always the PHP of the web server: then the driver is named);
 * - `log_path` and `tmp_path`, which hold the absolute paths of the old host;
 * - `live_site`: a stale value breaks the site (the front page answers with a redirect), so it follows the new address and is cleared if it
 *   names a host that is not part of the move;
 * - in dev mode: `mailonline` off, `robots` "noindex, nofollow" and a "[DEV] " in front of the site name, so that nobody mistakes the copy
 *   for the site.
 */
final class WriteConfiguration implements Step
{
    /** the `robots` setting of a dev copy: the meta tag of every page */
    public const string DEV_ROBOTS = 'noindex, nofollow';

    /** in front of the name of a dev copy */
    public const string DEV_MARK = '[DEV] ';

    public function id(): string
    {
        return 'write-configuration';
    }

    public function title(): string
    {
        return 'Adapt configuration.php to the new host';
    }

    public function plan(Context $context): Plan
    {
        $plan = new Plan();
        foreach ($this->changes($context) as $change) {
            $plan->add($change['change']);
            if (isset($change['notice'])) {
                $plan->notice(Notice::warning($change['notice'], 'configuration.php'));
            }
        }

        return $plan;
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $changes = $this->changes($context);
        if ($changes === []) {
            return new StepResult(0);
        }
        $source = State::source($context);
        $path = Installation::configurationPath($source->root);

        $values = [];
        foreach ($changes as $change) {
            $values[$change['property']] = $change['value'];
        }
        $current = JoomlaConfigFile::fromPath($path);
        $updated = $current->withMany($values);
        if ($updated->toString() === $current->toString()) {
            return new StepResult(0);
        }

        $backup = $context->journal->backupFile($path);
        $updated->writeTo($path);
        $context->journal->file($path, 'update', $backup);

        return new StepResult(count($changes));
    }

    /**
     * @return list<array{property: string, value: string|int|bool, change: Change, notice?: string}>
     */
    private function changes(Context $context): array
    {
        $source = State::source($context);
        $request = State::request($context);
        $old = $source->configuredDatabase;
        $new = $source->database;
        $found = [];
        $add = static function (string $property, string|int|bool $value, string $before, string $after, bool $secret = false, ?string $notice = null) use (&$found): void {
            $entry = ['property' => $property, 'value' => $value, 'change' => Change::update("file configuration.php $property", self::describe($property), $before, $after, secret: $secret)];
            if ($notice !== null) {
                $entry['notice'] = $notice;
            }
            $found[] = $entry;
        };

        // the database: the host setting carries the port when it is not the usual one
        $oldHost = $old->port === 3306 ? $old->host : $old->host . ':' . $old->port;
        $newHost = $new->port === 3306 ? $new->host : $new->host . ':' . $new->port;
        foreach ([
            ['host', $oldHost, $newHost, false],
            ['user', $old->user, $new->user, false],
            ['password', $old->password, $new->password, true],
            ['db', $old->name, $new->name, false],
            ['dbprefix', $old->prefix, $new->prefix, false],
        ] as [$property, $before, $after, $secret]) {
            if ($before !== $after) {
                $add($property, $after, $before, $after, $secret);
            }
        }

        $type = self::databaseType($source->dbType, driver: $new->driver);
        if ($type !== $source->dbType) {
            $add('dbtype', $type, $source->dbType, $type, notice: "Joomla is told to use the database interface \"$type\" (the site had \"{$source->dbType}\").");
        }

        $logPath = $request->serverPath() . '/administrator/logs';
        if ($source->logPath !== '' && $source->logPath !== $logPath) {
            $add('log_path', $logPath, $source->logPath, $logPath);
        }
        $tmpPath = $request->serverPath() . '/tmp';
        if ($source->tmpPath !== '' && $source->tmpPath !== $tmpPath) {
            $add('tmp_path', $tmpPath, $source->tmpPath, $tmpPath);
        }

        if ($source->liveSite !== '') {
            [$liveSite, $notice] = self::liveSite($source->liveSite, $request);
            if ($liveSite !== $source->liveSite) {
                $add('live_site', $liveSite, $source->liveSite, $liveSite, notice: $notice);
            }
        }

        if ($request->isDevMode()) {
            if ($source->mailOnline) {
                $add('mailonline', false, 'on', 'off');
            }
            if ($source->robots !== self::DEV_ROBOTS) {
                $add('robots', self::DEV_ROBOTS, $source->robots, self::DEV_ROBOTS);
            }
            if (!str_starts_with($source->siteName, self::DEV_MARK)) {
                $add('sitename', self::DEV_MARK . $source->siteName, $source->siteName, self::DEV_MARK . $source->siteName);
            }
        }

        return $found;
    }

    /**
     * The `dbtype` for the host: the one that the driver of the request names (`pdo` is `pdomysql`, `mysqli` is `mysqli`); with `auto` the
     * one the site has if its extension is loaded in this PHP, else the other one if that is.
     */
    public static function databaseType(string $configured, ?bool $mysqli = null, ?bool $pdo = null, string $driver = DatabaseSettings::AUTO): string
    {
        if ($driver === DatabaseSettings::PDO) {
            return 'pdomysql';
        }
        if ($driver === DatabaseSettings::MYSQLI) {
            return 'mysqli';
        }
        $mysqli ??= extension_loaded('mysqli');
        $pdo ??= extension_loaded('pdo_mysql');
        if ($configured === 'mysqli' && !$mysqli && $pdo) {
            return 'pdomysql';
        }
        if ($configured === 'pdomysql' && !$pdo && $mysqli) {
            return 'mysqli';
        }

        return $configured;
    }

    /**
     * The new `live_site`: the address with the new host (and the new base path, if one is given); cleared if the host is neither the
     * old one nor the new one of a mapping, because it would send visitors somewhere else. A move without a new domain leaves it alone.
     *
     * @return array{0: string, 1: string|null} the value, and a warning if it was cleared
     */
    public static function liveSite(string $liveSite, MigrationRequest $request): array
    {
        $parts = parse_url($liveSite);
        if (!is_array($parts) || !isset($parts['host'])) {
            return [$liveSite, null];
        }
        $host = strtolower($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
        $scheme = $parts['scheme'] ?? 'http';
        $path = rtrim($parts['path'] ?? '', '/');

        $mapping = $request->mappingFrom($host);
        if ($mapping !== null) {
            $host = $mapping->to;
            $scheme = $mapping->scheme ?? $scheme;
        } elseif (!$request->isTargetDomain($host) && $request->domains !== []) {
            return ['', "live_site named \"$liveSite\", which is not part of the move; it is cleared (a stale live_site makes the front page redirect)."];
        } elseif ($request->domains === [] && $request->physicalUri === null) {
            return [$liveSite, null];
        }
        if ($request->physicalUri !== null) {
            $path = rtrim($request->physicalUri, '/');
        }

        return [$scheme . '://' . $host . $path, null];
    }

    private static function describe(string $property): string
    {
        return match ($property) {
            'dbtype' => 'use the database interface that this PHP has',
            'log_path', 'tmp_path' => 'point to the directory on this host',
            'live_site' => 'follow the new address',
            'mailonline' => 'dev mode: the copy sends no mail',
            'robots' => 'dev mode: keep search engines out of every page',
            'sitename' => 'dev mode: mark the copy',
            default => 'point the site to the database it has now',
        };
    }
}
