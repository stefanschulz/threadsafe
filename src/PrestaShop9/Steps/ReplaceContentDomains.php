<?php

declare(strict_types=1);

namespace ThreadSafe\PrestaShop9\Steps;

use ThreadSafe\Core\Change;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\NullJournal;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Resources;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Database\SchemaInspector;
use ThreadSafe\Database\TableInfo;
use ThreadSafe\PrestaShop9\ConfigurationKeys;
use ThreadSafe\PrestaShop9\State;
use ThreadSafe\Replace\TableRewriter;
use ThreadSafe\Replace\TableRewriteResult;
use ThreadSafe\Replace\ValueRewriter;

/**
 * Replaces the old domain in the content of the database (CMS pages, product descriptions, module settings …),
 * keeping serialized PHP and JSON values valid. See docs/PHASE1.md (3.4) for the matching rules.
 *
 * Log and cache tables (resources/prestashop9/skip-tables.json) are skipped and only counted. The shop URL table
 * and the shop's own domain settings are left to {@see UpdateConfiguration}. Tables without a primary key are only
 * reported.
 */
final class ReplaceContentDomains implements Step
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

        foreach ($this->run($context, apply: false) as $result) {
            $this->describe($result, $plan);
        }
        $this->describeSkippedTables($context, $plan);

        return $plan;
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $rows = 0;
        foreach ($this->run($context, apply: true) as $result) {
            $rows += $result->rowsChanged;
        }

        return new StepResult($rows);
    }

    /**
     * @return list<TableRewriteResult> only tables in which something was found
     */
    private function run(Context $context, bool $apply): array
    {
        $request = State::request($context);
        $matcher = $request->urlMatcher();
        if ($matcher === null) {
            return [];
        }
        $db = State::connection($context);
        $rewriter = new TableRewriter($db, $matcher, new ValueRewriter($matcher), $request->chunkSize);
        $skip = $this->skippedTables($context);
        $journal = $apply ? $context->journal : new NullJournal();

        $results = [];
        foreach ((new SchemaInspector($db))->tables() as $name => $table) {
            if (isset($skip[$name])) {
                continue;
            }
            $result = $rewriter->rewrite($table, $this->rowFilter($context, $name), $request->replaceBare, $apply, $journal);
            if ($result->columns !== [] || $result->candidatesWithoutKey !== null && $result->candidatesWithoutKey > 0) {
                $results[] = $result;
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
        $names = [...Resources::strings('prestashop9/skip-tables.json', 'tables'), ...State::request($context)->skipTables, 'shop_url'];
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
        $names = array_map(static fn (string $n): string => "'" . $n . "'", ConfigurationKeys::domainValued());

        return $db->quote('name') . ' NOT IN (' . implode(', ', $names) . ')';
    }

    private function describe(TableRewriteResult $result, Plan $plan): void
    {
        if ($result->candidatesWithoutKey !== null) {
            $plan->notice(Notice::warning(
                "{$result->candidatesWithoutKey} row(s) mention the domain but the table has no primary key; not changed.",
                $result->table,
            ));

            return;
        }

        foreach ($result->columns as $column => $outcome) {
            $stats = $outcome->stats;
            if ($outcome->rowsChanged > 0) {
                $plan->add(Change::update(
                    "db {$result->table}.$column",
                    sprintf('replace the old domain (%d URL(s) in %d row(s))', $stats->urlsReplaced, $outcome->rowsChanged),
                    $outcome->sampleBefore,
                    $outcome->sampleAfter,
                    $outcome->rowsChanged,
                ));
            }
            if ($stats->bareFound > 0) {
                $plan->notice(Notice::info("{$stats->bareFound} value(s) mention the domain without a URL form and are left untouched (replace them with --replace-bare).", "{$result->table}.$column"));
            }
            if ($stats->bareReplaced > 0) {
                $plan->notice(Notice::info("{$stats->bareReplaced} bare domain name(s) replaced.", "{$result->table}.$column"));
            }
            if ($stats->emailsSkipped > 0) {
                $plan->notice(Notice::info("{$stats->emailsSkipped} e-mail address(es) at the old domain left untouched.", "{$result->table}.$column"));
            }
            if ($stats->unparseable > 0) {
                $plan->notice(Notice::warning("{$stats->unparseable} structured value(s) could not be parsed and were left untouched.", "{$result->table}.$column"));
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
