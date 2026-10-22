<?php

declare(strict_types=1);

namespace ThreadSafe\App;

use Closure;
use ThreadSafe\Admin\AuditLog;
use ThreadSafe\Admin\Config;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Retention\Retention;
use ThreadSafe\Retention\RetentionAction;
use ThreadSafe\Retention\RetentionPlan;
use ThreadSafe\Retention\RetentionState;
use ThreadSafe\Retention\RetentionStateStore;
use ThreadSafe\Storage\LocalStorage;

/**
 * Retention for the command, the pages of the web interface and the trigger (docs/PHASE5.md 3.4): the preview (a plan that changes
 * nothing), applying it by hand, and applying it by itself after a backup and once an hour.
 *
 * Applying by hand also **confirms the rules**: the person has seen what they do. Until then the trigger deletes on its own only when
 * a rule asks for a single backup (the ordinary case: one new backup, one old one goes); a rule that would delete more waits for the
 * person's go-ahead, shown on the profile page and in the audit log, so that a rule set wrongly cannot empty the storage unseen.
 * Every deletion is an audit entry with the rule that caused it.
 */
final class RetentionService
{
    /** Seconds between two runs of retention for a profile that makes no backups of its own. */
    public const int INTERVAL = 3600;

    /** How many deletions an unconfirmed rule may make on its own. */
    public const int FREE_DELETIONS = 1;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param Closure(): int|null $clock the time in seconds (for tests)
     */
    public function __construct(
        private readonly ProfileStore $profiles,
        private readonly RunStore $runs,
        private readonly RetentionStateStore $states,
        private readonly AuditLog $audit,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /**
     * The retention of a data directory.
     *
     * @param Closure(): int|null $clock for tests: the time in seconds
     *
     * @throws \ThreadSafe\Admin\ConfigException the configuration file is damaged
     */
    public static function create(string $data, ?Closure $clock = null): self
    {
        $config = Config::load($data . '/config.json');

        return new self(new ProfileStore($data . '/profiles'), new RunStore($data . '/runs'), new RetentionStateStore($data . '/retention'), new AuditLog($data . '/audit.log', $config->auditMaxBytes(), $clock), $clock);
    }

    /** What retention would do now; nothing is changed. */
    public function preview(SiteProfile $profile): RetentionPlan
    {
        return $this->retention($profile)->plan($profile);
    }

    public function state(string $profileId): RetentionState
    {
        return $this->states->load($profileId);
    }

    /** Whether the rules of the profile still wait for the person's go-ahead (they would delete more than they may on their own). */
    public function waiting(SiteProfile $profile): ?int
    {
        return $profile->retention === null ? null : $this->states->load($profile->id)->waiting;
    }

    /**
     * Applies the rules because a person asked for it (the command, the button): carries the plan out and confirms the rules.
     */
    public function apply(SiteProfile $profile, string $user = '-', string $address = ''): RetentionPlan
    {
        $result = $this->carryOut($profile, $user, $address);
        $this->states->save($profile->id, new RetentionState(self::hash($profile), ($this->clock)(), count($result->delete), $result->freed(), null));

        return $result;
    }

    /**
     * Applies the rules by itself (after a backup, once an hour). A rule that would delete more than {@see FREE_DELETIONS} backups and
     * has not been confirmed waits instead.
     *
     * @return RetentionPlan|null null: the profile has no rules, or the rules wait for the person
     */
    public function automatic(SiteProfile $profile): ?RetentionPlan
    {
        if ($profile->retention === null) {
            return null;
        }
        $state = $this->states->load($profile->id);
        $plan = $this->preview($profile);
        $now = ($this->clock)();
        if (!$plan->blocked && $state->confirmed !== self::hash($profile) && count($plan->delete) > self::FREE_DELETIONS) {
            if ($state->waiting !== count($plan->delete)) {
                $this->audit->record('retention.waiting', 'retention', '', ['profile' => $profile->id, 'backups' => count($plan->delete)]);
            }
            $this->states->save($profile->id, new RetentionState($state->confirmed, $now, $state->lastDeleted, $state->lastFreed, count($plan->delete)));

            return null;
        }
        $result = $this->carryOut($profile, 'retention', '');
        $this->states->save($profile->id, new RetentionState($state->confirmed, $now, count($result->delete), $result->freed(), null));

        return $result;
    }

    /**
     * Retention for every profile whose last run is an hour old, so that `maxAgeDays` works for a site that makes no backups any more.
     * A profile whose last backup run failed is left alone: after trouble, nothing is deleted on a hunch.
     *
     * @return array<string, RetentionPlan> what was done, by profile (only those that ran)
     */
    public function hourly(): array
    {
        $now = ($this->clock)();
        $failed = [];
        foreach ($this->runs->all('backup') as $run) {
            if ($run->status === RunStatus::Failed && is_string($run->state['profileId'] ?? null)) {
                $failed[$run->state['profileId']] = true;
            }
        }
        $done = [];
        foreach ($this->profiles->all() as $profile) {
            if ($profile->retention === null || isset($failed[$profile->id])) {
                continue;
            }
            $last = $this->states->load($profile->id)->lastRun;
            if ($last !== null && $now - $last < self::INTERVAL) {
                continue;
            }
            $result = $this->automatic($profile);
            if ($result !== null) {
                $done[$profile->id] = $result;
            }
        }

        return $done;
    }

    // ------------------------------------------------------------------------------------------------ inside

    private function carryOut(SiteProfile $profile, string $user, string $address): RetentionPlan
    {
        return $this->retention($profile)->apply($profile, function (RetentionAction $action) use ($profile, $user, $address): void {
            $this->audit->record('retention.deleted', $user, $address, ['profile' => $profile->id, 'backup' => $action->base(), 'rule' => $action->rule, 'reason' => $action->reason, 'bytes' => $action->bytes]);
        });
    }

    private function retention(SiteProfile $profile): Retention
    {
        return new Retention(new LocalStorage($profile->storage), $this->claimed(...), $this->clock);
    }

    /** A run that has not ended writes or reads the backup of that name: it is not to be deleted, and no leftover. */
    private function claimed(string $base): bool
    {
        foreach ($this->runs->all() as $run) {
            if ($run->status === RunStatus::Running && ($run->state['backup'] ?? null) === $base) {
                return true;
            }
        }

        return false;
    }

    private static function hash(SiteProfile $profile): string
    {
        return hash('sha256', (string) json_encode($profile->retention?->toArray()));
    }
}
