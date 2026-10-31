<?php

declare(strict_types=1);

namespace ThreadSafe\Migration;

use Closure;
use ThreadSafe\Engine\Budget;
use ThreadSafe\Replace\TableRewriteResult;

/**
 * The bookkeeping of replacing the old domain in the database content in ticks, the same for every website type: a list of jobs (one per
 * table, or two for a table that is rewritten twice), the job in progress, and the results of the finished jobs. What a job does is up to
 * the website type (it passes a closure).
 *
 * A table is rewritten chunk by chunk in the order of its primary key; when the budget is used up between two chunks the key of the last
 * row is kept ({@see encodeKey()}) and the next tick continues after it. A chunk that was written but not recorded (a crash) is harmless:
 * its rows no longer contain the old domain.
 */
final class ContentRewriteRun
{
    /**
     * @param list<array{string, string}> $jobs table name and kind (a word the website type understands)
     *
     * @return array<string, mixed>
     */
    public static function initialState(array $jobs): array
    {
        return ['jobs' => $jobs, 'job' => 0, 'after' => null, 'current' => null, 'results' => [], 'rows' => 0];
    }

    /**
     * Works on the jobs until the budget is used up (at least one chunk).
     *
     * @param array<string, mixed>                                                  $state
     * @param Closure(string, string, list<scalar|null>|null): TableRewriteResult $rewrite table, kind and the key to continue after
     *
     * @return array{array<string, mixed>, bool} the new state and whether all jobs are done
     */
    public static function tick(array $state, Budget $budget, Closure $rewrite): array
    {
        /** @var list<array{string, string}> $jobs */
        $jobs = $state['jobs'];
        $worked = false;
        while ((int) $state['job'] < count($jobs)) {
            if ($worked && $budget->exhausted()) {
                break;
            }
            $worked = true;
            [$table, $kind] = $jobs[(int) $state['job']];

            $result = $rewrite($table, $kind, self::decodeKey($state['after']));
            $current = $state['current'] === null ? new TableRewriteResult($table) : TableRewriteResult::fromArray((array) $state['current']);
            $current->merge($result);

            if ($result->stoppedAfter !== null) {
                $state['after'] = self::encodeKey($result->stoppedAfter);
                $state['current'] = $current->toArray();
                continue;
            }
            if ($current->columns !== [] || ($current->candidatesWithoutKey ?? 0) > 0) {
                $state['results'][] = $current->toArray();
            }
            $state['rows'] = (int) $state['rows'] + $current->rowsChanged;
            $state['job'] = (int) $state['job'] + 1;
            $state['after'] = null;
            $state['current'] = null;
        }

        return [$state, (int) $state['job'] >= count($jobs)];
    }

    /**
     * The results of the finished jobs.
     *
     * @param array<string, mixed> $state
     *
     * @return list<TableRewriteResult>
     */
    public static function results(array $state): array
    {
        return array_map(static fn (mixed $r): TableRewriteResult => TableRewriteResult::fromArray((array) $r), array_values((array) $state['results']));
    }

    /**
     * A primary key in a form that survives JSON: strings that are not valid UTF-8 become `{"b64": "..."}`.
     *
     * @param list<scalar|null> $key
     *
     * @return list<scalar|array{b64: string}|null>
     */
    public static function encodeKey(array $key): array
    {
        return array_map(static fn (mixed $v): mixed => is_string($v) && !mb_check_encoding($v, 'UTF-8') ? ['b64' => base64_encode($v)] : $v, $key);
    }

    /**
     * @return list<scalar|null>|null
     */
    public static function decodeKey(mixed $key): ?array
    {
        if (!is_array($key)) {
            return null;
        }

        return array_values(array_map(static fn (mixed $v): mixed => is_array($v) ? (string) base64_decode((string) ($v['b64'] ?? ''), true) : $v, $key));
    }

    private function __construct()
    {
    }
}
