<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9\Steps;

use InvalidArgumentException;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Database\Connection;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\PrestaShop9\DevMode\DevModeRules;
use ThreadSafe\PrestaShop9\DevMode\DevOperation;
use ThreadSafe\PrestaShop9\DevMode\DevRule;
use ThreadSafe\PrestaShop9\Exception\InvalidParametersFile;
use ThreadSafe\PrestaShop9\ParametersFile;
use ThreadSafe\PrestaShop9\State;

/**
 * Dev mode, database part: a copy of a live shop must not talk to the live world. Applies the rules of
 * resources/prestashop9/dev-mode.json – mail goes to a catcher or nowhere, PayPal is switched to sandbox without live
 * credentials, the PrestaShop Account tokens are removed, modules that report to the cloud are deactivated.
 *
 * Does nothing in production mode. Like the other database steps it works out its operations from the current
 * content in plan() and again in apply(), so a repeated run finds nothing to do. Search engine blocking (files) is
 * {@see BlockSearchEngines}.
 *
 * Customer data stays: this step does not anonymise (it says so in a warning).
 */
final class ApplyDevMode implements Step
{
    private const int MAX_LISTED_NAMES = 15;

    public function id(): string
    {
        return 'apply-dev-mode';
    }

    public function title(): string
    {
        return 'Dev mode: cut live mail, payment and cloud connections';
    }

    public function plan(Context $context): Plan
    {
        $request = State::request($context);
        $plan = new Plan();
        if (!$request->isDevMode()) {
            return $plan;
        }

        $rules = $this->rules($context);
        $db = State::connection($context);
        [$operations, $claimed] = $this->operations($db, $rules, $request);
        foreach ($operations as $operation) {
            $plan->add($operation->change());
        }

        $this->notices($context, $plan, $db, $request, $rules, $claimed);

        return $plan;
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $request = State::request($context);
        $db = State::connection($context);
        [$operations] = $this->operations($db, $this->rules($context), $request);

        $db->transaction(function () use ($db, $operations, $context): void {
            foreach ($operations as $operation) {
                $this->perform($db, $operation, $context);
            }
        });

        return new StepResult(array_sum(array_map(static fn (DevOperation $o): int => $o->count, $operations)));
    }

    /**
     * @return list<DevRule>
     */
    private function rules(Context $context): array
    {
        $request = State::request($context);
        $modules = State::moduleRules($context);
        try {
            $rules = DevModeRules::load($request->devRulesFile)->activeFor($request, $modules->knownNames());
        } catch (InvalidArgumentException $e) {
            throw new GuardFailed($e->getMessage(), 0, $e);
        }

        // What the rules of the installed modules say about dev mode (their ids are "module:<name>").
        foreach ($modules->devRules() as $rule) {
            if (!in_array($rule->id, $request->devSkip, true)) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * @param list<DevRule> $rules
     *
     * @return array{list<DevOperation>, array<int, true>} the operations and the ids of the configuration rows they touch
     */
    private function operations(Connection $db, array $rules, MigrationRequest $request): array
    {
        $configuration = $db->table('configuration');
        $claimed = [];
        $inserted = [];
        $handled = [];
        $operations = [];

        foreach ($rules as $rule) {
            foreach ($rule->set as $entry) {
                if (($entry['when'] === 'mailCatcher' && $request->mailCatcher === null) || ($entry['when'] === 'noMailCatcher' && $request->mailCatcher !== null)) {
                    continue;
                }
                $value = strtr($entry['value'], [
                    '{mailCatcher.host}' => $request->mailCatcher->host ?? '',
                    '{mailCatcher.port}' => (string) ($request->mailCatcher->port ?? ''),
                ]);
                $rows = $this->rowsNamed($db, $configuration, $entry['name']);
                $target = "db $configuration {$entry['name']}";
                if ($rows === []) {
                    if ($entry['create'] && !isset($inserted[$entry['name']])) {
                        $inserted[$entry['name']] = true;
                        $operations[] = DevOperation::insert($rule->id, $entry['name'], $value, $target, "dev mode ({$rule->id}): create the setting");
                    }
                    continue;
                }
                foreach ($rows as $row) {
                    $id = (int) $row['id_configuration'];
                    if (isset($claimed[$id]) || (string) ($row['value'] ?? '') === $value) {
                        continue;
                    }
                    $claimed[$id] = true;
                    $operations[] = DevOperation::set($rule->id, $entry['name'], $id, $row['value'] === null ? null : (string) $row['value'], $value, false, $target, "dev mode ({$rule->id}): set the value");
                }
            }

            foreach ($rule->clear as $name) {
                foreach ($this->rowsNamed($db, $configuration, $name) as $row) {
                    $id = (int) $row['id_configuration'];
                    if (isset($claimed[$id]) || (string) ($row['value'] ?? '') === '') {
                        continue;
                    }
                    $claimed[$id] = true;
                    $operations[] = DevOperation::set($rule->id, $name, $id, (string) $row['value'], '', true, "db $configuration $name", "dev mode ({$rule->id}): clear the value");
                }
            }

            foreach ($rule->remove as $pattern) {
                $rows = $db->select("SELECT id_configuration, name, value FROM {$db->quote($configuration)} WHERE name LIKE ? ORDER BY id_configuration", [$pattern]);
                foreach ($rows as $row) {
                    $id = (int) $row['id_configuration'];
                    if (isset($claimed[$id])) {
                        continue;
                    }
                    $claimed[$id] = true;
                    $operations[] = DevOperation::delete($rule->id, (string) $row['name'], $id, $row['value'] === null ? null : (string) $row['value'], "db $configuration {$row['name']}");
                }
            }

            foreach ($rule->emptyTables as $name) {
                $table = $db->table($name);
                if (isset($handled["table $table"]) || !$db->tableExists($table)) {
                    continue;
                }
                $handled["table $table"] = true;
                $rows = (int) $db->scalar('SELECT COUNT(*) FROM ' . $db->quote($table));
                if ($rows > 0) {
                    $operations[] = DevOperation::emptyTable($rule->id, $table, $rows);
                }
            }

            foreach ($rule->deactivateModules as $module) {
                if (isset($handled["module $module"])) {
                    continue;
                }
                $handled["module $module"] = true;
                $operation = $this->moduleOperation($db, $rule->id, $module);
                if ($operation !== null) {
                    $operations[] = $operation;
                }
            }
        }

        return [$operations, $claimed];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsNamed(Connection $db, string $configuration, string $name): array
    {
        return $db->select("SELECT id_configuration, value FROM {$db->quote($configuration)} WHERE name = ? ORDER BY id_configuration", [$name]);
    }

    /**
     * What PrestaShop's `Module::disable()` does to the database: the `module_shop` rows go and, when no shop is
     * left, `module.active` becomes 0. A module that is not installed or already off needs nothing.
     */
    private function moduleOperation(Connection $db, string $ruleId, string $module): ?DevOperation
    {
        $moduleTable = $db->table('module');
        $shopTable = $db->table('module_shop');
        if (!$db->tableExists($moduleTable)) {
            return null;
        }
        $row = $db->select("SELECT id_module, active FROM {$db->quote($moduleTable)} WHERE name = ?", [$module])[0] ?? null;
        if ($row === null) {
            return null;
        }
        $id = (int) $row['id_module'];
        $shopIds = [];
        if ($db->tableExists($shopTable)) {
            foreach ($db->select("SELECT id_shop FROM {$db->quote($shopTable)} WHERE id_module = ? ORDER BY id_shop", [$id]) as $shop) {
                $shopIds[] = (int) $shop['id_shop'];
            }
        }
        $active = (int) $row['active'] === 1;
        if (!$active && $shopIds === []) {
            return null;
        }

        return DevOperation::deactivateModule($ruleId, $module, $id, $active, $shopIds, $shopTable);
    }

    private function perform(Connection $db, DevOperation $operation, Context $context): void
    {
        $journal = $context->journal;
        $configuration = $db->quote($db->table('configuration'));
        $data = $operation->data;

        switch ($operation->kind) {
            case DevOperation::SET:
                $db->execute("UPDATE $configuration SET value = ?, date_upd = NOW() WHERE id_configuration = ?", [$operation->after, $data['id']]);
                $journal->database($db->table('configuration'), ['id_configuration' => $data['id'], 'name' => $data['name']], 'value', $operation->before, $operation->after);
                break;
            case DevOperation::INSERT:
                $db->execute("INSERT INTO $configuration (id_shop_group, id_shop, name, value, date_add, date_upd) VALUES (NULL, NULL, ?, ?, NOW(), NOW())", [$data['name'], $operation->after]);
                $journal->database($db->table('configuration'), ['name' => $data['name']], 'value', null, $operation->after);
                break;
            case DevOperation::DELETE:
                $db->execute("DELETE FROM $configuration WHERE id_configuration = ?", [$data['id']]);
                $journal->database($db->table('configuration'), ['id_configuration' => $data['id'], 'name' => $data['name']], 'value', $operation->before, null);
                break;
            case DevOperation::EMPTY_TABLE:
                // DELETE instead of TRUNCATE: it stays inside the transaction. The rows themselves are not journalled
                // (they are logs and synchronisation state of the live shop, and may be large).
                $table = (string) $data['table'];
                $db->execute('DELETE FROM ' . $db->quote($table));
                $journal->note('Emptied table ' . $table, ['rows' => $operation->count, 'rule' => $operation->ruleId]);
                break;
            case DevOperation::DEACTIVATE_MODULE:
                $shopTable = (string) $data['table'];
                if ($data['shopIds'] !== []) {
                    $db->execute('DELETE FROM ' . $db->quote($shopTable) . ' WHERE id_module = ?', [$data['id']]);
                    foreach ((array) $data['shopIds'] as $shopId) {
                        $journal->database($shopTable, ['id_module' => $data['id'], 'id_shop' => $shopId], '(row)', 'present', null);
                    }
                }
                if ($data['active'] === true) {
                    $db->execute('UPDATE ' . $db->quote($db->table('module')) . ' SET active = 0 WHERE id_module = ?', [$data['id']]);
                    $journal->database($db->table('module'), ['id_module' => $data['id'], 'name' => $data['name']], 'active', '1', '0');
                }
                break;
        }
    }

    /**
     * @param list<DevRule>    $rules
     * @param array<int, true> $claimed ids of the configuration rows the rules change
     */
    private function notices(Context $context, Plan $plan, Connection $db, MigrationRequest $request, array $rules, array $claimed): void
    {
        $skipped = $request->devSkip;
        if ($skipped !== []) {
            $plan->notice(Notice::warning('Dev rules switched off: ' . implode(', ', $skipped) . '. Their live connections stay active.'));
        }

        $ruleIds = array_map(static fn (DevRule $r): string => $r->id, $rules);
        if (in_array('mail', $ruleIds, true)) {
            $plan->notice($request->mailCatcher !== null
                ? Notice::info("All mail goes to the mail catcher {$request->mailCatcher}.")
                : Notice::info('The shop will send no mail at all. Give --mail-catcher=HOST:PORT to read the mails the shop would send.'));
        }

        $customers = $db->table('customer');
        if ($db->tableExists($customers)) {
            $count = (int) $db->scalar('SELECT COUNT(*) FROM ' . $db->quote($customers));
            if ($count > 0) {
                $plan->notice(Notice::warning("The database still holds $count customer account(s) with their addresses and orders: the dev mode does not anonymise them. Keep this copy private.", 'customer'));
            }
        }

        $this->cacheNotice($context, $plan, $db);
        $this->credentialNotice($plan, $db, $claimed);
    }

    /** Memcache and friends are shared with the live shop when the copy points at the same cache servers. */
    private function cacheNotice(Context $context, Plan $plan, Connection $db): void
    {
        try {
            $parameters = ParametersFile::fromPath(State::source($context)->root . '/app/config/parameters.php');
        } catch (InvalidParametersFile) {
            return;
        }
        $enabled = $parameters->get('ps_cache_enable');
        if ($enabled !== true && $enabled !== 1 && $enabled !== '1') {
            return;
        }

        $servers = $db->table('memcached_servers');
        $count = $db->tableExists($servers) ? (int) $db->scalar('SELECT COUNT(*) FROM ' . $db->quote($servers)) : 0;
        $plan->notice(Notice::warning(
            sprintf('The cache is enabled (ps_caching = %s, %d server(s) in %s). Make sure they are not the live shop\'s cache servers: the copy would read and overwrite the live cache.', (string) $parameters->get('ps_caching', '?'), $count, $servers),
            'app/config/parameters.php',
        ));
    }

    /**
     * Settings that look like credentials but no rule handles: listed by name (never with their values).
     *
     * @param array<int, true> $claimed
     */
    private function credentialNotice(Plan $plan, Connection $db, array $claimed): void
    {
        $rules = DevModeRules::load();
        if ($rules->warnPatterns === []) {
            return;
        }
        $configuration = $db->table('configuration');
        $likes = implode(' OR ', array_fill(0, count($rules->warnPatterns), 'name LIKE ?'));
        $rows = $db->select(
            "SELECT id_configuration, name FROM {$db->quote($configuration)} WHERE ($likes) AND CHAR_LENGTH(value) >= ? ORDER BY name",
            [...$rules->warnPatterns, $rules->warnMinLength],
        );

        $names = [];
        foreach ($rows as $row) {
            if (!isset($claimed[(int) $row['id_configuration']]) && !in_array($row['name'], $rules->warnIgnore, true)) {
                $names[(string) $row['name']] = true;
            }
        }
        if ($names === []) {
            return;
        }

        $listed = array_slice(array_keys($names), 0, self::MAX_LISTED_NAMES);
        $more = count($names) - count($listed);
        $plan->notice(Notice::warning(
            'These settings look like credentials of a live service and no dev rule handles them: ' . implode(', ', $listed) . ($more > 0 ? " and $more more" : '') . '. Check them before the copy goes online.',
            $configuration,
        ));
    }
}
