<?php

declare(strict_types=1);

namespace ThreadSafe\Joomla\Steps;

use ThreadSafe\Core\AlwaysRuns;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Exception\SelfTestFailed;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Http\HttpClient;
use ThreadSafe\Joomla\State;
use ThreadSafe\Replace\Stats;

/**
 * Checks that the migrated site answers: the front page comes back with HTTP 200 without a redirect to another host, carries no link to
 * the old domain, and says "Joomla" in its generator tag if it has one (the tag is on by default and sites may turn it off); and the
 * login of the administration answers with HTTP 200.
 *
 * The site is requested at the new domain (or at `--self-test-url` with the new domain as Host header), so a mapping is needed; without
 * one there is nothing to check.
 */
final class SelfTest implements Step, AlwaysRuns
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

        return $plan->notice(Notice::info(sprintf('After the migration the front page and /administrator/ are requested at %s (Host: %s) and must answer.', $request->selfTestUrl ?? "http://$host", $host)));
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $request = State::request($context);
        $host = $request->domains[0]->to ?? null;
        if (!$request->selfTest || $host === null) {
            return new StepResult(0);
        }

        $base = rtrim($request->selfTestUrl ?? "http://$host", '/') . rtrim($request->physicalUri ?? '', '/');
        $client = $this->client ?? new HttpClient($request->selfTestInsecure);
        $pinned = $request->selfTestUrl !== null;
        $matcher = $request->urlMatcher();

        $failures = [];
        $notices = [];

        $front = $client->get($base . '/', $host, $pinned);
        $problem = $front->problem ?? ($front->status !== 200 ? 'HTTP ' . $front->status : null);
        if ($problem === null && $matcher !== null) {
            $stats = new Stats();
            $matcher->rewrite($front->body, false, $stats);
            if ($stats->urlsReplaced > 0) {
                $problem = sprintf('%d link(s) on the page still point to the old domain', $stats->urlsReplaced);
            }
        }
        if ($problem === null && preg_match('~<meta\s+name="generator"\s+content="([^"]*)"~i', $front->body, $m) === 1 && stripos($m[1], 'joomla') === false) {
            $problem = 'the generator tag says "' . $m[1] . '", this is not Joomla';
        }
        if ($problem !== null) {
            $failures[] = "front page (/): $problem";
        } else {
            $notices[] = Notice::info('ok: front page (/) - HTTP 200');
        }

        $admin = $client->get($base . '/administrator/', $host, $pinned);
        $problem = $admin->problem ?? ($admin->status !== 200 ? 'HTTP ' . $admin->status : null);
        if ($problem !== null) {
            $failures[] = "administration (/administrator/): $problem";
        } else {
            $notices[] = Notice::info('ok: administration (/administrator/) - HTTP 200');
        }

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $context->logger->error("self-test FAILED: $failure");
            }

            throw new SelfTestFailed(sprintf('The migrated site does not work (%d check(s) failed): %s', count($failures), implode('; ', $failures)));
        }

        return new StepResult(0, $notices);
    }
}
