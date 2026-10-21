<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9\Steps;

use ThreadSafe\Core\Change;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\NullJournal;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Resources;
use ThreadSafe\Core\ResumableStep;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Core\StepTick;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Migration\ContentRewriteRun;
use ThreadSafe\Database\SchemaInspector;
use ThreadSafe\Migration\ContentReport;
use ThreadSafe\Database\TableInfo;
use ThreadSafe\PrestaShop9\ConfigurationKeys;
use ThreadSafe\PrestaShop9\State;
use ThreadSafe\Replace\ColumnResult;
use ThreadSafe\Replace\ColumnTreatment;
use ThreadSafe\Replace\TableRewriter;
use ThreadSafe\Replace\TableRewriteResult;
use ThreadSafe\Replace\ValueRewriter;

/**
 * Replaces the old domain in the content of the database (CMS pages, product descriptions, module settings …),
 * keeping serialized PHP and JSON values valid. See docs/PHASE1.md (3.4) for the matching rules.
 *
 * Log and cache tables (plugins/prestashop9/resources/skip-tables.json, plus those named by module rules) are skipped and only
 * counted. The shop URL table and the shop's own domain settings are left to {@see UpdateConfiguration}. Tables
 * without a primary key are only reported.
 *
 * Module rules refine the generic engine for the modules installed in the shop: the format and bare host names of
 * single columns, configuration keys to keep or to treat as bare domains. A table outside a clean installation that
 * mentions the domain and that no rule knows is reported as "no rule" - the hint to write one.
 */
final class ReplaceContentDomains implements ResumableStep
{
    public function id(): string
    {
        return 'replace-content-domains';
    }

    public function title(): string
    {
        return 'Replace the old domain in database content';
    }

    public function plan(Context $context): Plan
    {
        $plan = new Plan();
        $request = State::request($context);
        if ($request->urlMatcher() === null) {
            return $plan->notice(Notice::info('No domain mapping given: nothing to replace in the content.'));
        }

        $this->describe($context, $this->run($context), $plan);

        return $plan;
    }

    public function planBrief(Context $context): Plan
    {
        $plan = new Plan();
        if (State::request($context)->urlMatcher() === null) {
            return $plan->notice(Notice::info('No domain mapping given: nothing to replace in the content.'));
        }

        return $plan->add(Change::update('db content', 'replace the old domain in the text columns of the database (the details come when it is done)'));
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $state = [];
        do {
            $tick = $this->applyTick($context, Budget::unlimited(), $state);
            $state = $tick->state;
        } while (!$tick->finished);

        return $tick->result ?? new StepResult(0);
    }

    public function applyTick(Context $context, Budget $budget, array $state): StepTick
    {
        $request = State::request($context);
        $matcher = $request->urlMatcher();
        if ($matcher === null) {
            return new StepTick([], true, new StepResult(0));
        }
        $db = State::connection($context);
        $tables = (new SchemaInspector($db))->tables();
        $skip = $this->skippedTables($context);
        $rules = State::moduleRules($context);
        $configuration = $db->table('configuration');
        $bareKeys = $rules->configurationKeys('replace-bare');

        if ($state === []) {
            $jobs = [];
            foreach (array_keys($tables) as $name) {
                if (isset($skip[$name])) {
                    continue;
                }
                $jobs[] = [$name, 'content'];
                if ($name === $configuration && $bareKeys !== []) {
                    $jobs[] = [$name, 'bare'];
                }
            }
            $state = ContentRewriteRun::initialState($jobs);
        }

        $rewriter = new TableRewriter($db, $matcher, new ValueRewriter($matcher), $request->chunkSize);
        [$state, $finished] = ContentRewriteRun::tick($state, $budget, function (string $name, string $kind, ?array $after) use ($context, $request, $rewriter, $tables, $rules, $db, $bareKeys, $budget): TableRewriteResult {
            $table = $tables[$name] ?? null;
            if (!$table instanceof TableInfo) {
                return new TableRewriteResult($name);      // dropped in the meantime
            }
            if ($kind === 'bare') {
                // Settings a module rule says hold a bare domain name.
                $filter = $db->quote('name') . ' IN (' . implode(', ', array_map($db->quotedString(...), $bareKeys)) . ')';

                return $rewriter->rewrite($table, $filter, true, true, $context->journal, [], $budget, $after);
            }
            $treatments = [];
            foreach ($rules->columnsOf($db->unprefixed($name)) as $column => $rule) {
                $treatments[$column] = new ColumnTreatment($rule->form, $rule->bare);
            }

            return $rewriter->rewrite($table, $this->rowFilter($context, $name), $request->replaceBare, true, $context->journal, $treatments, $budget, $after);
        });

        if (!$finished) {
            return new StepTick($state, false, null, null, sprintf('replacing the old domain in the database (table %d of %d)', min((int) $state['job'] + 1, count($state['jobs'])), count($state['jobs'])));
        }
        $plan = new Plan();
        $this->describe($context, ContentRewriteRun::results($state), $plan);

        return new StepTick($state, true, new StepResult((int) $state['rows']), $plan);
    }

    /**
     * @param list<TableRewriteResult> $results
     */
    private function describe(Context $context, array $results, Plan $plan): void
    {
        foreach ($results as $result) {
            ContentReport::describe($result, $plan);
        }
        $this->describeTablesWithoutRule($context, $results, $plan);
        $this->describeSkippedTables($context, $plan);
    }

    /**
     * A dry run over the whole database: what would be changed.
     *
     * @return list<TableRewriteResult> only tables in which something was found
     */
    private function run(Context $context): array
    {
        $request = State::request($context);
        $matcher = $request->urlMatcher();
        if ($matcher === null) {
            return [];
        }
        $db = State::connection($context);
        $rewriter = new TableRewriter($db, $matcher, new ValueRewriter($matcher), $request->chunkSize);
        $skip = $this->skippedTables($context);
        $journal = new NullJournal();
        $rules = State::moduleRules($context);
        $configuration = $db->table('configuration');
        $bareKeys = $rules->configurationKeys('replace-bare');

        $results = [];
        foreach ((new SchemaInspector($db))->tables() as $name => $table) {
            if (isset($skip[$name])) {
                continue;
            }
            $treatments = [];
            foreach ($rules->columnsOf($db->unprefixed($name)) as $column => $rule) {
                $treatments[$column] = new ColumnTreatment($rule->form, $rule->bare);
            }
            $result = $rewriter->rewrite($table, $this->rowFilter($context, $name), $request->replaceBare, false, $journal, $treatments);
            if ($result->columns !== [] || $result->candidatesWithoutKey !== null && $result->candidatesWithoutKey > 0) {
                $results[] = $result;
            }
            if ($name === $configuration && $bareKeys !== []) {
                // Settings a module rule says hold a bare domain name.
                $filter = $db->quote('name') . ' IN (' . implode(', ', array_map($db->quotedString(...), $bareKeys)) . ')';
                $result = $rewriter->rewrite($table, $filter, true, false, $journal);
                if ($result->columns !== []) {
                    $results[] = $result;
                }
            }
        }

        return $results;
    }

    /**
     * @return array<string, true> prefixed table names that are not rewritten
     */
    private function skippedTables(Context $context): array
    {
        $db = State::connection($context);
        $names = [
            ...Resources::strings('prestashop9/skip-tables.json', 'tables'),
            ...State::request($context)->skipTables,
            ...State::moduleRules($context)->skipTables(),
            'shop_url',
        ];
        $skip = [];
        foreach ($names as $name) {
            $skip[$db->table($name)] = true;
        }

        return $skip;
    }

    /** The shop's own domain settings are set by UpdateConfiguration, not rewritten like content. */
    private function rowFilter(Context $context, string $table): string
    {
        $db = State::connection($context);
        if ($table !== $db->table('configuration')) {
            return '';
        }
        // The shop's own domain settings are set by UpdateConfiguration; keys a module rule keeps or treats as bare
        // domains are handled separately.
        $rules = State::moduleRules($context);
        $names = array_map($db->quotedString(...), [
            ...ConfigurationKeys::domainValued(),
            ...$rules->configurationKeys('keep'),
            ...$rules->configurationKeys('replace-bare'),
        ]);

        return $db->quote('name') . ' NOT IN (' . implode(', ', $names) . ')';
    }

    /**
     * Tables outside a clean installation that mention the domain and that no module rule knows: they belong to a module
     * the generic engine handles on its own. The report names them so that a rule can be written (or the table
     * be skipped).
     *
     * @param list<TableRewriteResult> $results
     */
    private function describeTablesWithoutRule(Context $context, array $results, Plan $plan): void
    {
        $db = State::connection($context);
        $core = array_flip(Resources::strings('prestashop9/core-tables.json', 'tables'));
        $known = array_flip(State::moduleRules($context)->knownTables());

        foreach ($results as $result) {
            $name = $db->unprefixed($result->table);
            if (isset($core[$name]) || isset($known[$name])) {
                continue;
            }
            $found = array_sum(array_map(static fn (ColumnResult $c): int => $c->stats->urlsReplaced + $c->stats->bareFound, $result->columns));
            if ($found > 0) {
                $plan->notice(Notice::warning(
                    sprintf('No module rule for table "%s": %d mention(s) of the domain were handled by the generic engine only. If it belongs to a module, write a rule for it (docs/PHASE1.md 3.8).', $name, $found),
                    $result->table,
                ));
            }
        }
    }

    private function describeSkippedTables(Context $context, Plan $plan): void
    {
        $skip = $this->skippedTables($context);
        $tables = (new SchemaInspector(State::connection($context)))->tables();
        foreach ($skip as $name => $_) {
            $table = $tables[$name] ?? null;
            if ($table instanceof TableInfo && $table->rowEstimate > 0 && $name !== State::connection($context)->table('shop_url')) {
                $plan->notice(Notice::info(sprintf('Skipped log/cache table (about %d row(s)).', $table->rowEstimate), $name));
            }
        }
    }
}
