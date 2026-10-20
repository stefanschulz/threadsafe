<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9\Steps;

use ThreadSafe\Core\Context;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\ConnectionFailed;
use ThreadSafe\Migration\ShopUrlRow;
use ThreadSafe\Migration\SourceState;
use ThreadSafe\PrestaShop9\Exception\InvalidParametersFile;
use ThreadSafe\PrestaShop9\ParametersFile;
use ThreadSafe\PrestaShop9\State;

/**
 * Looks at the installation and the database before anything is planned, and refuses to continue (exit code 2)
 * if this is not what the request expects: no PrestaShop 9, database not reachable or not MariaDB, no shop URL,
 * or the domain to replace is not the shop's domain. Changes nothing.
 */
final class ReadSourceState implements Step
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
            throw new GuardFailed("The shop root \"$root\" is not a directory.");
        }

        $version = $this->detectVersion($root);
        $major = (int) explode('.', $version)[0];
        if ($major !== 9) {
            throw new GuardFailed("Only PrestaShop 9 is supported, found version $version.");
        }

        try {
            $configured = ParametersFile::fromPath($root . '/app/config/parameters.php')->database();
        } catch (InvalidParametersFile $e) {
            throw new GuardFailed('Cannot read the database settings: ' . $e->getMessage(), 0, $e);
        }
        $database = $request->database ?? $configured;

        try {
            $connection = Connection::open($database);
        } catch (ConnectionFailed $e) {
            throw new GuardFailed($e->getMessage(), 0, $e);
        }
        if (!$connection->isMariaDb()) {
            throw new GuardFailed("The database server reports \"{$connection->serverVersion}\"; only MariaDB is supported.");
        }

        $shopUrls = $this->readShopUrls($connection);
        $this->checkDomains($context, $shopUrls, $plan);

        $source = new SourceState($root, $version, $this->detectAdminDirectory($root), $configured, $database, $connection->serverVersion, $shopUrls);
        State::store($context, $source, $connection);

        $plan->notice(Notice::info(sprintf(
            'PrestaShop %s, database "%s" (prefix "%s") on MariaDB %s, %d shop URL(s), back office folder: %s.',
            $version,
            $database->name,
            $database->prefix,
            $connection->serverVersion,
            count($shopUrls),
            $source->adminDirectory ?? 'not found',
        )));

        $rules = State::moduleRules($context)->names();
        if ($rules !== []) {
            $plan->notice(Notice::info('Module rules in use: ' . implode(', ', $rules) . '.'));
        }

        return $plan;
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        return new StepResult(0);
    }

    private function detectVersion(string $root): string
    {
        $file = $root . '/src/Core/Version.php';
        $content = is_file($file) ? file_get_contents($file) : false;
        if ($content === false || preg_match("/const\\s+VERSION\\s*=\\s*'([^']+)'/", $content, $m) !== 1) {
            throw new GuardFailed("\"$root\" does not look like a PrestaShop 9 installation (src/Core/Version.php not found).");
        }

        return $m[1];
    }

    /** The back office folder has an arbitrary name; it is the directory that contains themes/new-theme. */
    private function detectAdminDirectory(string $root): ?string
    {
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_dir($root . '/' . $entry . '/themes/new-theme')) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return list<ShopUrlRow>
     */
    private function readShopUrls(Connection $db): array
    {
        $table = $db->table('shop_url');
        if (!$db->tableExists($table)) {
            throw new GuardFailed("Table \"$table\" does not exist: wrong table prefix, or this is not a PrestaShop database.");
        }

        $rows = [];
        foreach ($db->select("SELECT id_shop_url, id_shop, domain, domain_ssl, physical_uri, virtual_uri, main, active FROM {$db->quote($table)} ORDER BY id_shop_url") as $row) {
            $rows[] = new ShopUrlRow(
                (int) $row['id_shop_url'],
                (int) $row['id_shop'],
                (string) $row['domain'],
                (string) $row['domain_ssl'],
                (string) $row['physical_uri'],
                (string) $row['virtual_uri'],
                (bool) $row['main'],
                (bool) $row['active'],
            );
        }
        if ($rows === []) {
            throw new GuardFailed("Table \"$table\" has no shop URL: the shop is not configured.");
        }

        return $rows;
    }

    /**
     * @param list<ShopUrlRow> $shopUrls
     */
    private function checkDomains(Context $context, array $shopUrls, Plan $plan): void
    {
        $request = State::request($context);
        $present = [];
        foreach ($shopUrls as $row) {
            $present[strtolower($row->domain)] = true;
            $present[strtolower($row->domainSsl)] = true;
        }

        foreach ($request->domains as $mapping) {
            $hasOld = isset($present[$mapping->from]);
            $hasNew = isset($present[$mapping->to]);
            if (!$hasOld && !$hasNew) {
                throw new GuardFailed(sprintf(
                    'The domain "%s" is not a domain of this shop (shop URLs: %s). Wrong shop, or the wrong domain was given.',
                    $mapping->from,
                    implode(', ', array_keys($present)),
                ));
            }
            if (!$hasOld) {
                $plan->notice(Notice::info("The shop URLs already use \"{$mapping->to}\"; the domain \"{$mapping->from}\" is not present any more.", $mapping->from));
            }
        }

        if (!$request->isRestoreInPlace()) {
            foreach (array_keys($present) as $domain) {
                if ($request->mappingFrom($domain) === null && !$request->isTargetDomain($domain)) {
                    $plan->notice(Notice::warning("Shop URL domain \"$domain\" has no mapping and stays as it is.", $domain));
                }
            }
        }
    }
}
