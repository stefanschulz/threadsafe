<?php

declare(strict_types=1);

namespace ThreadSafe\WordPress\Steps;

use ThreadSafe\Core\AlwaysRuns;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Exception\SelfTestFailed;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\Step;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Http\HttpClient;
use ThreadSafe\Http\HttpResult;
use ThreadSafe\Replace\Stats;
use ThreadSafe\WordPress\State;

/**
 * Asks the migrated site whether it works, as a visitor would (docs/PHASE9.md 3.5, step 8): the front page answers 200 and carries no link to
 * the old domain, `/wp-login.php` shows its login form, and the REST index (`/?rest_route=/`, which works with every kind of permalinks) is
 * JSON that names the new address.
 *
 * A site that has **not** been adapted answers every front-end request with `301` to its old address (work package 1, finding 3): the client
 * reports a redirect to another host as a problem, which is exactly what this finds. Only when the request names a new domain; without one
 * there is no address to ask for.
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

        return $plan->notice(Notice::info(sprintf('After the migration the front page, /wp-login.php and the REST index are requested at %s (Host: %s) and must answer.', $request->selfTestUrl ?? "http://$host", $host)));
    }

    public function apply(Context $context, Plan $plan): StepResult
    {
        $request = State::request($context);
        $mapping = $request->domains[0] ?? null;
        if (!$request->selfTest || $mapping === null) {
            return new StepResult(0);
        }
        $host = $mapping->to;
        $base = rtrim($request->selfTestUrl ?? "http://$host", '/') . rtrim($request->physicalUri ?? '', '/');
        $client = $this->client ?? new HttpClient($request->selfTestInsecure);
        $pinned = $request->selfTestUrl !== null;
        $matcher = $request->urlMatcher();
        $failures = [];
        $notices = [];

        $check = function (string $label, string $path, callable $verify) use ($client, $base, $host, $pinned, $matcher, &$failures, &$notices): void {
            $result = $client->get($base . $path, $host, $pinned);
            $problem = $result->problem ?? ($result->status !== 200 ? 'HTTP ' . $result->status : null);
            if ($problem === null && $matcher !== null) {
                $stats = new Stats();
                $matcher->rewrite($result->body, false, $stats);
                if ($stats->urlsReplaced > 0) {
                    $problem = sprintf('%d link(s) in the answer still point to the old domain', $stats->urlsReplaced);
                }
            }
            $problem ??= $verify($result);
            if ($problem !== null) {
                $failures[] = "$label ($path): $problem";
            } else {
                $notices[] = Notice::info("ok: $label ($path) - HTTP 200");
            }
        };

        $check('front page', '/', static fn (HttpResult $r): ?string => null);
        $check('login', '/wp-login.php', static fn (HttpResult $r): ?string => str_contains($r->body, 'loginform') || str_contains($r->body, 'user_login') ? null : 'the page has no login form');
        $check('REST index', '/?rest_route=/', static function (HttpResult $r): ?string {
            $data = json_decode($r->body, true);

            return is_array($data) && isset($data['home']) ? null : 'the answer is not the REST index of WordPress';
        });

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $context->logger->error("self-test FAILED: $failure");
            }
            throw new SelfTestFailed(sprintf('The migrated site does not work (%d check(s) failed): %s', count($failures), implode('; ', $failures)));
        }

        return new StepResult(0, $notices);
    }
}
