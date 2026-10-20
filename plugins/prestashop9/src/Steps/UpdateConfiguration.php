<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9\Steps;

use ThreadSafe\Core\Change;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Exception\GuardFailed;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Database\Connection;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\PrestaShop9\ConfigurationKeys;
use ThreadSafe\PrestaShop9\ShopUrlRow;
use ThreadSafe\PrestaShop9\State;

/**
 * Sets the shop's own addresses: `PS_SHOP_DOMAIN`, `PS_SHOP_DOMAIN_SSL`, the SSL switches and media servers in
 * `configuration`, and `domain`, `domain_ssl`, `physical_uri` in `shop_url`.
 *
 * Operations are computed from the current database content in plan() and again in apply(), so the step needs no
 * hand-over between the two and is idempotent: once the values are right, the plan is empty.
 */
final class UpdateConfiguration implements Step
{
    public function id(): string
    {
        return 'update-configuration';
    }

    public function title(): string
    {
        return 'Set shop domain, SSL and shop URLs';
    }

    public function plan(Context $context): Plan
    {
        $request = State::request($context);
        $plan = new Plan();

        if ($request->isRestoreInPlace() && $request->ssl === null && $request->physicalUri === null) {
            return $plan->notice(Notice::info('No new domain, SSL mode or path requested: the shop addresses stay as they are.'));
        }

        $operations = $this->operations($context, $plan);
        foreach ($operations as $operation) {
            $plan->add($operation->change());
        }

        return $plan;
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $db = State::connection($context);
        $operations = $this->operations($context, new Plan());

        $db->transaction(function () use ($db, $operations, $context): void {
            foreach ($operations as $operation) {
                $this->perform($db, $operation, $context);
            }
        });

        return new StepResult(count($operations));
    }

    /**
     * @return list<ConfigurationOperation>
     */
    private function operations(Context $context, Plan $notices): array
    {
        $request = State::request($context);
        $db = State::connection($context);
        $source = State::source($context);

        return [
            ...$this->configurationDomains($db, $request, $notices),
            ...$this->configurationSsl($db, $request),
            ...$this->shopUrls($source->shopUrls, $request, $db),
        ];
    }

    /**
     * @return list<ConfigurationOperation>
     */
    private function configurationDomains(Connection $db, MigrationRequest $request, Plan $notices): array
    {
        $table = $db->table('configuration');
        $names = ConfigurationKeys::domainValued();
        $rows = $db->select(
            "SELECT id_configuration, name, value FROM {$db->quote($table)} WHERE name IN (" . implode(',', array_fill(0, count($names), '?')) . ') ORDER BY id_configuration',
            $names,
        );

        $operations = [];
        foreach ($rows as $row) {
            $value = (string) ($row['value'] ?? '');
            if ($value === '') {
                continue;
            }
            $mapping = $request->mappingFrom($value);
            if ($mapping !== null) {
                $operations[] = new ConfigurationOperation(
                    $table,
                    'id_configuration',
                    (int) $row['id_configuration'],
                    'value',
                    $value,
                    $mapping->to,
                    sprintf('db %s %s', $table, $row['name']),
                    'set the new domain',
                    true,
                );
            } elseif (!$request->isRestoreInPlace() && !$request->isTargetDomain($value)) {
                $notices->notice(Notice::warning("{$row['name']} is \"$value\", which has no domain mapping; left as it is.", (string) $row['name']));
            }
        }

        return $operations;
    }

    /**
     * @return list<ConfigurationOperation>
     */
    private function configurationSsl(Connection $db, MigrationRequest $request): array
    {
        if ($request->ssl === null) {
            return [];
        }

        $table = $db->table('configuration');
        $wanted = [
            ConfigurationKeys::SSL_ENABLED => $request->ssl->sslEnabled() ? '1' : '0',
            ConfigurationKeys::SSL_EVERYWHERE => $request->ssl->everywhere() ? '1' : '0',
        ];

        $operations = [];
        foreach ($wanted as $name => $value) {
            $rows = $db->select("SELECT id_configuration, value FROM {$db->quote($table)} WHERE name = ?", [$name]);
            if ($rows === []) {
                $operations[] = new ConfigurationOperation($table, 'name', $name, 'value', null, $value, "db $table $name", 'create the setting', false, insert: true);
                continue;
            }
            foreach ($rows as $row) {
                if ((string) $row['value'] !== $value) {
                    $operations[] = new ConfigurationOperation($table, 'id_configuration', (int) $row['id_configuration'], 'value', (string) $row['value'], $value, "db $table $name", 'set SSL mode ' . $request->ssl->value, false);
                }
            }
        }

        return $operations;
    }

    /**
     * @param list<ShopUrlRow> $rows
     *
     * @return list<ConfigurationOperation>
     */
    private function shopUrls(array $rows, MigrationRequest $request, Connection $db): array
    {
        $table = $db->table('shop_url');
        $operations = [];
        $resulting = [];

        foreach ($rows as $row) {
            $domainMapping = $request->mappingFrom($row->domain);
            $sslMapping = $request->mappingFrom($row->domainSsl);
            $touched = $domainMapping !== null || $sslMapping !== null || ($request->isRestoreInPlace() && $row->main);

            $newDomain = $domainMapping->to ?? $row->domain;
            $newSsl = $sslMapping->to ?? $row->domainSsl;
            $newPath = $request->physicalUri !== null && $touched ? $request->physicalUri : $row->physicalUri;

            $key = strtolower($newDomain) . '|' . $newPath . '|' . $row->virtualUri;
            if (isset($resulting[$key])) {
                throw new GuardFailed("Two shop URLs would end up as \"$newDomain$newPath{$row->virtualUri}\"; the domain mapping is ambiguous.");
            }
            $resulting[$key] = true;

            foreach ([['domain', $row->domain, $newDomain], ['domain_ssl', $row->domainSsl, $newSsl], ['physical_uri', $row->physicalUri, $newPath]] as [$column, $old, $new]) {
                if ($old !== $new) {
                    $operations[] = new ConfigurationOperation($table, 'id_shop_url', $row->id, $column, $old, $new, "db $table #{$row->id} $column", 'set the new shop URL', false);
                }
            }
        }

        return $operations;
    }

    private function perform(Connection $db, ConfigurationOperation $operation, Context $context): void
    {
        $table = $db->quote($operation->table);
        $isConfiguration = str_ends_with($operation->table, 'configuration');

        if ($operation->insert) {
            $db->execute(
                "INSERT INTO $table (id_shop_group, id_shop, name, value, date_add, date_upd) VALUES (NULL, NULL, ?, ?, NOW(), NOW())",
                [$operation->keyValue, $operation->new],
            );
            $context->journal->database($operation->table, [$operation->keyColumn => $operation->keyValue], $operation->column, null, $operation->new);

            return;
        }

        $db->execute(
            "UPDATE $table SET {$db->quote($operation->column)} = ?" . ($isConfiguration ? ', date_upd = NOW()' : '') . " WHERE {$db->quote($operation->keyColumn)} = ?",
            [$operation->new, $operation->keyValue],
        );
        $context->journal->database($operation->table, [$operation->keyColumn => $operation->keyValue], $operation->column, $operation->old, $operation->new);
    }
}
