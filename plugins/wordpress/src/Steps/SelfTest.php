<?php

declare(strict_types=1);

namespace ThreadSafe\WordPress\Steps;

use Closure;
use RuntimeException;
use ThreadSafe\Core\AlwaysRuns;
use ThreadSafe\Core\Context;
use ThreadSafe\Core\Exception\SelfTestFailed;
use ThreadSafe\Core\Notice;
use ThreadSafe\Core\Plan;
use ThreadSafe\Core\ResumableStep;
use ThreadSafe\Core\StepResult;
use ThreadSafe\Core\StepTick;
use ThreadSafe\Database\DatabaseError;
use ThreadSafe\Engine\Budget;
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
 *
 * **Maintenance mode** (field test feedback): the first request to a restored site starts the cron of WordPress, which runs what is overdue -
 * among it the automatic updates -, and while an update is installed WordPress answers every request with `503` and "Briefly unavailable for
 * scheduled maintenance" (a file `.maintenance`). So the step works in ticks and
 *
 *  - **waits** while the answer is the maintenance page, up to {@see MAINTENANCE_SECONDS}, and fails only if it does not end;
 *  - after the checks have passed, **lets the cron finish** what the checks started (the transient `doing_cron` of the options table), and asks
 *    once more (only if it was seen working), so that the restore does not end - and the page does not offer the link to the site - while WordPress is still busy.
 */
final class SelfTest implements ResumableStep, AlwaysRuns
{
    /** How long a site may stay in maintenance mode before the self-test gives up. */
    public const int MAINTENANCE_SECONDS = 180;

    /** How long to wait for the cron to show itself after the first request (it is started by that request, a moment later). */
    public const float CRON_GRACE_SECONDS = 5.0;

    /** How long the cron may work before the self-test goes on without it. */
    public const int CRON_SECONDS = 120;

    /** WordPress counts a cron run as dead after this long (`WP_CRON_LOCK_TIMEOUT`). */
    private const int CRON_LOCK_SECONDS = 60;

    private const float POLL_SECONDS = 2.0;

    /** @var Closure(float): void */
    private readonly Closure $sleep;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param Closure(float): void|null $sleep for tests: waits that many seconds
     * @param Closure(): float|null     $clock for tests: the time in seconds
     */
    public function __construct(private readonly ?HttpClient $client = null, ?Closure $sleep = null, ?Closure $clock = null)
    {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
        $this->clock = $clock ?? static fn (): float => microtime(true);
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
        return $this->planBrief($context);
    }

    public function planBrief(Context $context): Plan
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

        return $plan->notice(Notice::info(sprintf('After the migration the front page, /wp-login.php and the REST index are requested at %s (Host: %s) and must answer; a site in maintenance mode (an update of its own cron) is waited for.', $request->selfTestUrl ?? "http://$host", $host)));
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
        $mapping = $request->domains[0] ?? null;
        if (!$request->selfTest || $mapping === null) {
            return new StepTick([], true, new StepResult(0));
        }
        $now = ($this->clock)();
        $state += ['started' => $now, 'phase' => 'check', 'settled' => false, 'waited' => 0.0, 'settleFrom' => 0.0, 'cronSeen' => false, 'notes' => [], 'ok' => []];

        do {
            if ($state['phase'] === 'done') {
                return new StepTick($state, true, new StepResult(0, [...$this->waitedNotices($state), ...array_values(array_map(static fn (string $message): Notice => Notice::info($message), (array) $state['ok']))]));
            }
            if ($state['phase'] === 'settle') {
                $state = $this->settle($context, $state);
                if ($state['phase'] === 'settle') {
                    $budget->note('letting WordPress finish what its first requests started (cron)');

                    return new StepTick($state, false, null, null, 'letting WordPress finish what its first requests started (its cron)');
                }
                continue;
            }

            [$failures, $notices, $maintenance] = $this->probe($context);
            if ($failures === [] && !$maintenance) {
                if (($state['sawMaintenance'] ?? false) === true) {
                    $state['waited'] = ($this->clock)() - (float) $state['started'];
                }
                if (!$state['settled']) {
                    $state['ok'] = array_map(static fn (Notice $notice): string => $notice->message, $notices);
                    $state['phase'] = 'settle';
                    $state['settleFrom'] = ($this->clock)();

                    continue;
                }

                return new StepTick($state, true, new StepResult(0, [...$this->waitedNotices($state), ...$notices]));
            }
            if ($failures === []) {                // so the pages are the maintenance page, nothing else is wrong
                $elapsed = ($this->clock)() - (float) $state['started'];
                if ($elapsed >= self::MAINTENANCE_SECONDS) {
                    $context->logger->error('self-test FAILED: the site is still in maintenance mode');
                    throw new SelfTestFailed(sprintf(
                        'The migrated site does not work: WordPress is still in maintenance mode after %d seconds ("Briefly unavailable for scheduled maintenance"). Its files and database are restored. An update that WordPress started by itself may have stopped: delete the file .maintenance in the site directory if it is there, and run the self-test again.',
                        (int) $elapsed,
                    ));
                }
                $state['sawMaintenance'] = true;
                $state['phase'] = 'check';
                $state['settled'] = false;
                $this->pause();
                $line = sprintf('WordPress is in maintenance mode (an update is running): waiting, %d of at most %d seconds', (int) $elapsed, self::MAINTENANCE_SECONDS);
                $budget->note($line);

                return new StepTick($state, false, null, null, $line);
            }
            foreach ($failures as $failure) {
                $context->logger->error("self-test FAILED: $failure");
            }
            throw new SelfTestFailed(sprintf('The migrated site does not work (%d check(s) failed): %s', count($failures), implode('; ', $failures)));
        } while (!$budget->exhausted());

        return new StepTick($state, false, null, null, 'checking the migrated site');
    }

    /**
     * Whether the cron has finished what the checks started. The first request to a site starts the cron by a request of its own to the site's
     * address, a moment later; so for a short grace time its absence means nothing. While it works (the transient `doing_cron`) the step waits,
     * and when it is quiet the checks are asked once more.
     *
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function settle(Context $context, array $state): array
    {
        $now = ($this->clock)();
        $active = $this->cronActive($context, $now);
        if ($active === null) {
            $state['phase'] = 'done';          // no database to look at: nothing to wait for

            return $state;
        }
        $since = $now - (float) $state['settleFrom'];
        if ($active) {
            $state['cronSeen'] = true;
            if ($since >= self::CRON_SECONDS) {
                $state['notes'][] = sprintf('The cron of WordPress was still working after %d seconds; the self-test went on without waiting longer.', self::CRON_SECONDS);
                $state['phase'] = 'check';
                $state['settled'] = true;

                return $state;
            }
            $this->pause();

            return $state;
        }
        if ($state['cronSeen']) {
            $state['phase'] = 'check';         // it worked and is quiet now: ask the pages once more
            $state['settled'] = true;

            return $state;
        }
        if ($since >= self::CRON_GRACE_SECONDS) {
            $state['phase'] = 'done';          // it never showed itself: nothing has changed

            return $state;
        }
        ($this->sleep)(1.0);

        return $state;
    }

    /** Whether a run of the cron is working right now; null if that cannot be told. */
    private function cronActive(Context $context, float $now): ?bool
    {
        try {
            $db = State::connection($context);
            $value = $db->scalar('SELECT option_value FROM ' . $db->quote($db->table('options')) . " WHERE option_name = '_transient_doing_cron'");
        } catch (RuntimeException | DatabaseError) {
            return null;
        }
        if ($value === null || $value === false || !is_numeric($value)) {
            return false;
        }

        return $now - (float) $value < self::CRON_LOCK_SECONDS;     // a run that died leaves its mark behind; WordPress itself ignores it after a minute
    }

    private function pause(): void
    {
        ($this->sleep)(self::POLL_SECONDS);
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return list<Notice>
     */
    private function waitedNotices(array $state): array
    {
        $notices = [];
        if ((float) $state['waited'] > 0.0) {
            $notices[] = Notice::info(sprintf('WordPress was in maintenance mode for about %d seconds after the first requests (an update that its own cron started); the self-test waited for it to end.', (int) $state['waited']));
        }
        foreach ((array) $state['notes'] as $note) {
            $notices[] = Notice::warning((string) $note);
        }

        return $notices;
    }

    /**
     * One round of the three checks.
     *
     * @return array{0: list<string>, 1: list<Notice>, 2: bool} what is wrong (other than maintenance), the notices of what is right, and whether
     *                                                          any page was the maintenance page of WordPress
     */
    private function probe(Context $context): array
    {
        $request = State::request($context);
        $mapping = $request->domains[0];
        $host = $mapping->to;
        $base = rtrim($request->selfTestUrl ?? "http://$host", '/') . rtrim($request->physicalUri ?? '', '/');
        $client = $this->client ?? new HttpClient($request->selfTestInsecure);
        $pinned = $request->selfTestUrl !== null;
        $matcher = $request->urlMatcher();
        $failures = [];
        $notices = [];
        $maintenance = false;

        $check = function (string $label, string $path, callable $verify) use ($client, $base, $host, $pinned, $matcher, &$failures, &$notices, &$maintenance): void {
            $result = $client->get($base . $path, $host, $pinned);
            if (self::isMaintenance($result)) {
                $maintenance = true;

                return;
            }
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

        return [$failures, $notices, $maintenance];
    }

    /**
     * The page that WordPress shows while a file `.maintenance` is there: status `503`, a `Retry-After` header, and (unless the site has a
     * `wp-content/maintenance.php` of its own) the words "Briefly unavailable for scheduled maintenance".
     */
    public static function isMaintenance(HttpResult $result): bool
    {
        return $result->problem === null
            && $result->status === 503
            && ($result->header('retry-after') !== null || str_contains($result->body, 'scheduled maintenance'));
    }
}
