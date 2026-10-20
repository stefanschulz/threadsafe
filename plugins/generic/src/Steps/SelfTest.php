<?php

declare(strict_types=1);

namespace ThreadSafe\Generic\Steps;

use ThreadSafe\Core\AlwaysRuns;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Exception\SelfTestFailed;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Generic\State;
use ThreadSafe\Http\HttpClient;
use ThreadSafe\Replace\Stats;

/**
 * Checks that the migrated site answers: its home page must come back with HTTP 200 without a redirect loop or a
 * redirect to another host, and must not link to the old domain. A generic site has no known pages beyond that.
 *
 * The site is requested at the new domain (or at `--self-test-url` with the new domain as Host header), so a mapping
 * is needed; without one there is nothing to check.
 */
final class SelfTest implements AlwaysRuns
{
    public function __construct(private readonly ?HttpClient $client = null)
    {
    }

    public function id(): string
    {
        return 'self-test';
    }

    public function title(): string
    {
        return 'Check that the migrated site answers';
    }

    public function plan(Context $context): Plan
    {
        $request = State::request($context);
        $plan = new Plan();
        if (!$request->selfTest) {
            return $plan->notice(Notice::warning('The self-test is switched off: nothing proves that the migrated site serves.'));
        }
        $host = $request->domains[0]->to ?? null;
        if ($host === null) {
            return $plan->notice(Notice::info('No new domain given: the self-test has no address to request.'));
        }

        return $plan->notice(Notice::info(sprintf('After the migration the home page is requested at %s (Host: %s) and must answer.', $request->selfTestUrl ?? "http://$host", $host)));
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $request = State::request($context);
        $host = $request->domains[0]->to ?? null;
        if (!$request->selfTest || $host === null) {
            return new StepResult(0);
        }

        $base = rtrim($request->selfTestUrl ?? "http://$host", '/');
        $result = ($this->client ?? new HttpClient($request->selfTestInsecure))->get($base . '/', $host, $request->selfTestUrl !== null);

        $problem = $result->problem ?? ($result->status !== 200 ? 'HTTP ' . $result->status : null);
        $matcher = $request->urlMatcher();
        if ($problem === null && $matcher !== null) {
            $stats = new Stats();
            $matcher->rewrite($result->body, false, $stats);
            if ($stats->urlsReplaced > 0) {
                $problem = sprintf('%d link(s) on the page still point to the old domain', $stats->urlsReplaced);
            }
        }
        if ($problem !== null) {
            $context->logger->error("self-test FAILED: home page - $problem");

            throw new SelfTestFailed("The migrated site does not work (1 check(s) failed): home page (/): $problem");
        }

        return new StepResult(0, [Notice::info('ok: home page (/) - HTTP 200')]);
    }
}
