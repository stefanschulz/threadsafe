<?php

declare(strict_types=1);

namespace ThreadSafe\Generic\Steps;

use ThreadSafe\Core\Change;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\NullJournal;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\ResumableStep;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Core\StepTick;
use ThreadSafe\Database\Connection;
use ThreadSafe\Database\SchemaInspector;
use ThreadSafe\Database\TableInfo;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Generic\State;
use ThreadSafe\Migration\ContentReport;
use ThreadSafe\Migration\ContentRewriteRun;
use ThreadSafe\Migration\MigrationRequest;
use ThreadSafe\Replace\TableRewriter;
use ThreadSafe\Replace\TableRewriteResult;
use ThreadSafe\Replace\ValueRewriter;

/**
 * Replaces the old domain in every text column of every table of the database, keeping serialized PHP and JSON values
 * valid. A generic site has no list of log or cache tables: tables that must stay as they are are named with
 * `--skip-tables`. Tables without a primary key are only reported.
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
        if (State::connection($context) === null) {
            return $plan->notice(Notice::warning('No database given: there is no content to replace here. Files of a generic site are not rewritten.'));
        }

        foreach ($this->run($context) as $result) {
            ContentReport::describe($result, $plan);
        }

        return $plan;
    }

    public function planBrief(Context $context): Plan
    {
        $plan = new Plan();
        if (State::request($context)->urlMatcher() === null) {
            return $plan->notice(Notice::info('No domain mapping given: nothing to replace in the content.'));
        }
        if (State::connection($context) === null) {
            return $plan->notice(Notice::warning('No database given: there is no content to replace here. Files of a generic site are not rewritten.'));
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
        $db = State::connection($context);
        if ($matcher === null || $db === null) {
            return new StepTick([], true, new StepResult(0));
        }
        $tables = (new SchemaInspector($db))->tables();

        if ($state === []) {
            $skip = array_flip($request->skipTables);
            $jobs = [];
            foreach (array_keys($tables) as $name) {
                if (!isset($skip[$name]) && !isset($skip[$db->unprefixed($name)])) {
                    $jobs[] = [$name, 'content'];
                }
            }
            $state = ContentRewriteRun::initialState($jobs);
        }

        $rewriter = new TableRewriter($db, $matcher, new ValueRewriter($matcher), $request->chunkSize);
        [$state, $finished] = ContentRewriteRun::tick($state, $budget, static function (string $name, string $kind, ?array $after) use ($context, $request, $rewriter, $tables, $budget, $db): TableRewriteResult {
            $table = $tables[$name] ?? null;
            if (!$table instanceof TableInfo) {
                return new TableRewriteResult($name);      // dropped in the meantime
            }

            return $rewriter->rewrite($table, '', $request->replaceBare, true, $context->journal, [], $budget, $after, self::skippedColumns($request, $db, $name));
        });

        if (!$finished) {
            return new StepTick($state, false, null, null, sprintf('replacing the old domain in the database (table %d of %d)', min((int) $state['job'] + 1, count($state['jobs'])), count($state['jobs'])));
        }
        $plan = new Plan();
        foreach (ContentRewriteRun::results($state) as $result) {
            ContentReport::describe($result, $plan);
        }

        return new StepTick($state, true, new StepResult((int) $state['rows']), $plan);
    }

    /**
     * The columns of the table that the request leaves alone (`table.column`, the table without its prefix).
     *
     * @return list<string>
     */
    private static function skippedColumns(MigrationRequest $request, Connection $db, string $table): array
    {
        $plain = $db->unprefixed($table);
        $columns = [];
        foreach ($request->skipColumns as $entry) {
            [$name, $column] = explode('.', $entry, 2);
            if ($name === $plain || $name === $table) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * @return list<TableRewriteResult> only tables in which something was found
     */
    private function run(Context $context): array
    {
        $request = State::request($context);
        $matcher = $request->urlMatcher();
        $db = State::connection($context);
        if ($matcher === null || $db === null) {
            return [];
        }

        $skip = array_flip($request->skipTables);
        $rewriter = new TableRewriter($db, $matcher, new ValueRewriter($matcher), $request->chunkSize);
        $journal = new NullJournal();

        $results = [];
        foreach ((new SchemaInspector($db))->tables() as $name => $table) {
            if (isset($skip[$name]) || isset($skip[$db->unprefixed($name)])) {
                continue;
            }
            $result = $rewriter->rewrite($table, '', $request->replaceBare, false, $journal, [], null, null, self::skippedColumns($request, $db, $name));
            if ($result->columns !== [] || $result->candidatesWithoutKey !== null && $result->candidatesWithoutKey > 0) {
                $results[] = $result;
            }
        }

        return $results;
    }
}
