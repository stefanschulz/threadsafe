<?php

declare(strict_types=1);

namespace ThreadSafe\Generic\Steps;

use ThreadSafe\Core\Context;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\NullJournal;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Database\SchemaInspector;
use ThreadSafe\Generic\State;
use ThreadSafe\Migration\ContentReport;
use ThreadSafe\Replace\TableRewriter;
use ThreadSafe\Replace\TableRewriteResult;
use ThreadSafe\Replace\ValueRewriter;

/**
 * Replaces the old domain in every text column of every table of the database, keeping serialized PHP and JSON values
 * valid. A generic site has no list of log or cache tables: tables that must stay as they are are named with
 * `--skip-tables`. Tables without a primary key are only reported.
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
        if (State::connection($context) === null) {
            return $plan->notice(Notice::warning('No database given: there is no content to replace here. Files of a generic site are not rewritten.'));
        }

        foreach ($this->run($context, apply: false) as $result) {
            ContentReport::describe($result, $plan);
        }

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
        $db = State::connection($context);
        if ($matcher === null || $db === null) {
            return [];
        }

        $skip = array_flip($request->skipTables);
        $rewriter = new TableRewriter($db, $matcher, new ValueRewriter($matcher), $request->chunkSize);
        $journal = $apply ? $context->journal : new NullJournal();

        $results = [];
        foreach ((new SchemaInspector($db))->tables() as $name => $table) {
            if (isset($skip[$name]) || isset($skip[$db->unprefixed($name)])) {
                continue;
            }
            $result = $rewriter->rewrite($table, '', $request->replaceBare, $apply, $journal);
            if ($result->columns !== [] || $result->candidatesWithoutKey !== null && $result->candidatesWithoutKey > 0) {
                $results[] = $result;
            }
        }

        return $results;
    }
}
