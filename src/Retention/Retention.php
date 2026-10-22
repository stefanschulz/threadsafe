<?php

declare(strict_types=1);

namespace ThreadSafe\Retention;

use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Storage\BackupCatalog;
use ThreadSafe\Storage\BackupEntry;
use ThreadSafe\Storage\LocalStorage;
use ThreadSafe\Storage\SpaceCheck;

/**
 * What stays in the storage of a profile and what goes (docs/PHASE5.md 3.4).
 *
 * The rules of the profile are `keep` (at most this many backups), `maxAgeDays` (none older) and `maxBytes` (together at most this
 * much); every rule is optional. Only **complete, usable** backups count (status `ok`, `ok-with-warnings`, `unverified`). Never
 * deleted: the newest usable backup, whatever the rules say (a failing schedule or a rule set wrongly must not leave the site without
 * a backup); a **pinned** backup, which does not count towards `keep`; a backup that a run is writing or a check is reading. A faulty or
 * incomplete backup is left alone (it may be recoverable by hand). Leftovers of runs that died (work directories, `.part` files) that no
 * run claims and that are older than a week are removed as well.
 *
 * {@see plan()} works out what would be deleted, oldest first, with the numbers recomputed after each deletion (a plan changes nothing:
 * it is the dry run and the preview); {@see apply()} carries the plan out. When the storage cannot be read nothing is deleted.
 */
final class Retention
{
    /** Unfinished files younger than this many days may belong to a run that is only slow. */
    public const int LEFTOVER_DAYS = 7;

    private const array USABLE = ['ok', 'ok-with-warnings', 'unverified'];

    /** @var Closure(string): bool */
    private readonly Closure $claimed;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param Closure(string): bool|null $claimed whether a run is writing or reading the backup with this base name
     * @param Closure(): int|null        $clock   the time in seconds (for tests)
     */
    public function __construct(private readonly LocalStorage $storage, ?Closure $claimed = null, ?Closure $clock = null)
    {
        $this->claimed = $claimed ?? static fn (string $base): bool => false;
        $this->clock = $clock ?? time(...);
    }

    public function plan(SiteProfile $profile): RetentionPlan
    {
        $rules = $profile->retention;
        if ($rules === null) {
            return new RetentionPlan(notes: ['The profile has no retention rules.']);
        }
        $directory = $this->storage->directory();
        if (!is_dir($directory)) {
            // a profile whose first backup has not been made, or a storage that is not mounted: either way nothing is deleted
            return new RetentionPlan(notes: ['The storage directory does not exist (yet; the first backup makes it), so there is nothing to delete.'], blocked: true);
        }
        if (!is_readable($directory) || @scandir($directory) === false) {
            return new RetentionPlan(notes: ['The storage directory cannot be read, so nothing is deleted.'], blocked: true);
        }

        $now = ($this->clock)();
        $notes = [];
        $left = 0;
        $usable = [];
        foreach ((new BackupCatalog($this->storage))->entries($profile->id, false) as $entry) {
            if ($entry->condition === BackupEntry::COMPLETE && $entry->sidecar !== null && in_array($entry->label(), self::USABLE, true)) {
                $usable[] = $entry;
            } elseif ($entry->condition !== BackupEntry::UNFINISHED) {
                ++$left;
            }
        }
        if ($left > 0) {
            $notes[] = $left . ' backup' . ($left === 1 ? ' is' : 's are') . ' faulty, incomplete or unreadable and left alone.';
        }

        $bytes = $this->size($usable);
        $actions = [];
        $remaining = $usable;
        $cutoff = $rules->maxAgeDays === null ? null : $now - $rules->maxAgeDays * 86400;
        while (($next = $this->next($remaining, $rules->keep, $cutoff, $rules->maxBytes)) !== null) {
            [$index, $rule] = $next;
            $entry = $remaining[$index];
            $actions[] = new RetentionAction($entry, $rule, $this->reason($rule, $remaining, $entry, $rules->keep, $rules->maxAgeDays, $rules->maxBytes, $now), $entry->sidecar?->size() ?? 0);
            array_splice($remaining, $index, 1);
        }

        if ($rules->maxBytes !== null && $this->size($remaining) > $rules->maxBytes) {
            $notes[] = 'The size limit of ' . SpaceCheck::format($rules->maxBytes) . ' cannot be met: ' . SpaceCheck::format($this->size($remaining)) . ' remain, and the rest is protected (the newest backup, pinned or in use).';
        }
        $protected = count(array_filter($remaining, fn (BackupEntry $e): bool => $this->isProtected($e, $remaining)));
        if ($protected > 0) {
            $notes[] = $protected . ' backup' . ($protected === 1 ? ' is' : 's are') . ' protected: the newest usable one' . (count(array_filter($remaining, static fn (BackupEntry $e): bool => $e->sidecar?->pinned === true)) > 0 ? ', the pinned ones' : '') . ' and any that is in use.';
        }

        return new RetentionPlan($actions, $this->leftovers($now), $notes, count($usable), $bytes);
    }

    /**
     * Carries a fresh plan out. Each backup is looked at once more just before it goes: if it has been pinned or taken into use since
     * the plan was made it stays.
     *
     * @param (Closure(RetentionAction): void)|null $onDelete called after each deletion (the audit log)
     *
     * @return RetentionPlan what was done: the deletions that were carried out, the notes of the plan, and any that were skipped
     */
    public function apply(SiteProfile $profile, ?Closure $onDelete = null): RetentionPlan
    {
        $plan = $this->plan($profile);
        if ($plan->blocked || $plan->isEmpty()) {
            return $plan;
        }

        $notes = $plan->notes;
        $deleted = [];
        $catalog = new BackupCatalog($this->storage);
        foreach ($plan->delete as $action) {
            $entry = $catalog->entry($action->entry->name, false);
            if ($entry->sidecar === null || $entry->sidecar->pinned || ($this->claimed)($action->base())) {
                $notes[] = $action->base() . ' was left: it was pinned or taken into use meanwhile.';

                continue;
            }
            $this->storage->removeBackup($action->entry->name);
            $deleted[] = $action;
            if ($onDelete !== null) {
                $onDelete($action);
            }
        }
        $leftovers = [];
        foreach ($plan->leftovers as $action) {
            if (($this->claimed)($action->base())) {
                continue;
            }
            $this->storage->removeBackup($action->entry->name);
            $leftovers[] = $action;
            if ($onDelete !== null) {
                $onDelete($action);
            }
        }

        return new RetentionPlan($deleted, $leftovers, $notes, $plan->usable - count($deleted), $plan->bytes - array_sum(array_map(static fn (RetentionAction $a): int => $a->bytes, $deleted)));
    }

    // ------------------------------------------------------------------------------------------------ the rules

    /**
     * The next backup to delete: the oldest one that a rule asks for and that is not protected.
     *
     * @param list<BackupEntry> $remaining usable backups, newest first
     *
     * @return array{int, string}|null its index and the rule
     */
    private function next(array $remaining, ?int $keep, ?int $cutoff, ?int $maxBytes): ?array
    {
        $counted = array_values(array_filter($remaining, static fn (BackupEntry $e): bool => $e->sidecar?->pinned !== true));
        $rank = [];
        foreach ($counted as $position => $entry) {
            $rank[$entry->name->base()] = $position;
        }
        $oldestUnprotected = null;
        for ($i = count($remaining) - 1; $i >= 0; --$i) {
            $entry = $remaining[$i];
            if ($this->isProtected($entry, $remaining)) {
                continue;
            }
            $oldestUnprotected ??= $i;
            if ($keep !== null && ($rank[$entry->name->base()] ?? 0) >= $keep) {
                return [$i, RetentionAction::KEEP];
            }
            if ($cutoff !== null && $entry->name->time->getTimestamp() < $cutoff) {
                return [$i, RetentionAction::AGE];
            }
        }
        if ($maxBytes !== null && $oldestUnprotected !== null && $this->size($remaining) > $maxBytes) {
            return [$oldestUnprotected, RetentionAction::SIZE];
        }

        return null;
    }

    /**
     * @param list<BackupEntry> $remaining
     */
    private function isProtected(BackupEntry $entry, array $remaining): bool
    {
        return $entry === ($remaining[0] ?? null) || $entry->sidecar?->pinned === true || ($this->claimed)($entry->name->base());
    }

    /**
     * @param list<BackupEntry> $remaining
     */
    private function reason(string $rule, array $remaining, BackupEntry $entry, ?int $keep, ?int $maxAgeDays, ?int $maxBytes, int $now): string
    {
        return match ($rule) {
            RetentionAction::KEEP => sprintf('keep %d: there are %d backups that count, this one is beyond the newest %d', (int) $keep, count(array_filter($remaining, static fn (BackupEntry $e): bool => $e->sidecar?->pinned !== true)), (int) $keep),
            RetentionAction::AGE => sprintf('older than %d days (%d days old)', (int) $maxAgeDays, (int) floor(($now - $entry->name->time->getTimestamp()) / 86400)),
            default => sprintf('together the backups are %s, over the limit of %s', SpaceCheck::format($this->size($remaining)), SpaceCheck::format((int) $maxBytes)),
        };
    }

    /**
     * @param list<BackupEntry> $entries
     */
    private function size(array $entries): int
    {
        return array_sum(array_map(static fn (BackupEntry $e): int => $e->sidecar?->size() ?? 0, $entries));
    }

    // ---------------------------------------------------------------------------------------------- leftovers

    /**
     * @return list<RetentionAction>
     */
    private function leftovers(int $now): array
    {
        $actions = [];
        foreach ($this->storage->unfinished() as $name) {
            if (($this->claimed)($name->base())) {
                continue;
            }
            $newest = 0;
            $bytes = 0;
            foreach ([...$this->storage->files($name), $this->storage->directory() . '/.work-' . $name->base()] as $path) {
                [$time, $size] = $this->touched($path);
                $newest = max($newest, $time);
                $bytes += $size;
            }
            if ($newest === 0 || $now - $newest < self::LEFTOVER_DAYS * 86400) {
                continue;
            }
            $actions[] = new RetentionAction(new BackupEntry($name, null, BackupEntry::UNFINISHED), RetentionAction::LEFTOVER, sprintf('unfinished files of a run that died, last touched %d days ago', (int) floor(($now - $newest) / 86400)), $bytes);
        }

        return $actions;
    }

    /**
     * @return array{int, int} the latest modification time and the size of a file, or of everything below a directory
     */
    private function touched(string $path): array
    {
        if (is_file($path)) {
            return [(int) @filemtime($path), (int) @filesize($path)];
        }
        if (!is_dir($path)) {
            return [0, 0];
        }
        $time = (int) @filemtime($path);
        $size = 0;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $time = max($time, (int) $file->getMTime());
            $size += (int) $file->getSize();
        }

        return [$time, $size];
    }
}
