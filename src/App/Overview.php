<?php

declare(strict_types=1);

namespace ThreadSafe\App;

use Closure;
use Throwable;
use ThreadSafe\Engine\Run;
use ThreadSafe\Engine\RunStatus;
use ThreadSafe\Engine\RunStore;
use ThreadSafe\Profile\ProfileStore;
use ThreadSafe\Profile\SiteProfile;
use ThreadSafe\Storage\BackupCatalog;
use ThreadSafe\Storage\BackupEntry;
use ThreadSafe\Storage\LocalStorage;
use ThreadSafe\Storage\SpaceCheck;

/**
 * What the dashboard shows (docs/PHASE4.md 3.9): for every profile the newest backup, how many there are and how big, the run that is
 * going or has gone wrong, and the warnings. It reads only what is cheap - the sidecar files, the run records, `stat` and the free
 * space of the disk; it opens no archive, makes no sidecar file again and never touches a database (that is the "Test" button).
 */
final class Overview
{
    public const string OK = 'ok';

    public const string WARNING = 'warning';

    public const string PROBLEM = 'problem';

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param Closure(): int|null $clock the time in seconds (for tests)
     * @param int                 $warnAfterDays no successful backup for this long is a warning
     */
    public function __construct(
        private readonly ProfileStore $profiles,
        private readonly RunStore $runs,
        private readonly BackupService $backups,
        private readonly int $warnAfterDays = 7,
        ?Closure $clock = null,
        private readonly ?Closure $freeSpace = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** The word for a level, as the badge of a card and of a profile says it. */
    public static function label(string $level): string
    {
        return [self::OK => 'Healthy', self::WARNING => 'Needs a look', self::PROBLEM => 'Problem'][$level] ?? $level;
    }

    /**
     * The card of one profile (the page of the profile has the same state and warnings as the dashboard).
     *
     * @return array<string, mixed>
     */
    public function forProfile(SiteProfile $profile): array
    {
        $runs = [];
        foreach ($this->runs->all('backup') as $run) {
            if (($run->state['profileId'] ?? null) === $profile->id) {
                $runs[] = ['run' => $run, 'state' => $this->backups->state($run)];
            }
        }

        return $this->card($profile, $runs);
    }

    /**
     * @return array{cards: list<array<string, mixed>>, totals: array{profiles: int, backups: int, bytes: int, running: int}}
     */
    public function build(): array
    {
        $byProfile = [];
        $running = 0;
        foreach ($this->runs->all('backup') as $run) {
            $profile = is_string($run->state['profileId'] ?? null) ? $run->state['profileId'] : '';
            $state = $this->backups->state($run);
            $running += $state === 'active' ? 1 : 0;
            $byProfile[$profile][] = ['run' => $run, 'state' => $state];
        }

        $cards = [];
        $totals = ['profiles' => 0, 'backups' => 0, 'bytes' => 0, 'running' => $running];
        foreach (SiteProfile::sortedByName($this->profiles->all()) as $profile) {
            $card = $this->card($profile, $byProfile[$profile->id] ?? []);
            $cards[] = $card;
            ++$totals['profiles'];
            $totals['backups'] += $card['backups'];
            $totals['bytes'] += $card['bytes'];
        }

        return ['cards' => $cards, 'totals' => $totals];
    }

    /**
     * @param list<array{run: Run, state: string}> $runs the backup runs on record for this profile
     *
     * @return array<string, mixed>
     */
    private function card(SiteProfile $profile, array $runs): array
    {
        $now = ($this->clock)();
        $warnings = [];
        $level = self::OK;
        $raise = static function (string $to) use (&$level): void {
            $order = [self::OK => 0, self::WARNING => 1, self::PROBLEM => 2];
            if ($order[$to] > $order[$level]) {
                $level = $to;
            }
        };
        $add = static function (string $kind, string $text) use (&$warnings, $raise): void {
            $warnings[] = ['level' => $kind, 'text' => $text];
            $raise($kind);
        };

        $entries = [];
        try {
            $entries = (new BackupCatalog(new LocalStorage($profile->storage)))->entries($profile->id, false);
        } catch (Throwable) {
            $add(self::PROBLEM, 'The backups cannot be listed: the storage directory cannot be read.');
        }

        $bytes = 0;
        $last = null;
        $lastGood = null;
        foreach ($entries as $entry) {
            if ($entry->sidecar !== null && $entry->condition === BackupEntry::COMPLETE) {
                $bytes += $entry->sidecar->size();
            }
            $last ??= $entry->sidecar !== null ? $entry : null;
            if ($lastGood === null && $entry->condition === BackupEntry::COMPLETE && $entry->sidecar !== null && in_array($entry->label(), ['ok', 'ok-with-warnings', 'unverified'], true)) {
                $lastGood = $entry;
            }
        }

        if (!is_dir($profile->path)) {
            $add(self::PROBLEM, 'The site directory no longer exists: ' . $profile->path);
        }
        if (is_dir($profile->storage)) {
            if (!is_writable($profile->storage)) {
                $add(self::PROBLEM, 'The storage directory is not writable.');
            } elseif (!(new LocalStorage($profile->storage))->isProtected()) {
                $add(self::WARNING, 'The storage directory has no protection files (.htaccess and the like); keep it outside the web root.');
            }
        }

        if ($lastGood === null) {
            $add($entries === [] ? self::WARNING : self::PROBLEM, $entries === [] ? 'There is no backup yet.' : 'There is no backup that can be used.');
        } else {
            $age = $now - $lastGood->name->time->getTimestamp();
            if ($age > $this->warnAfterDays * 86400) {
                $add(self::WARNING, sprintf('No successful backup for %d days.', (int) floor($age / 86400)));
            }
        }
        if ($last !== null && in_array($last->label(), ['faulty', 'incomplete'], true)) {
            $add(self::PROBLEM, 'The newest backup is ' . $last->label() . ($last->problem !== null ? ': ' . $last->problem : '.'));
        }

        $state = null;
        foreach ($runs as $item) {
            if ($item['state'] === 'finished') {
                continue;
            }
            if ($state === null || $item['state'] === 'active') {
                $state = $item;
            }
        }
        if ($state !== null) {
            if ($state['state'] === 'failed') {
                $add(self::PROBLEM, 'The last backup run failed: ' . self::reason($state['run']));
            } elseif ($state['state'] === 'interrupted') {
                $add(self::WARNING, 'A backup run was interrupted; it can be continued or given up.');
            }
        }

        $free = $this->free($profile->storage);
        if ($free !== null && $last !== null && $last->sidecar !== null && $free < (int) ceil($last->sidecar->size() * 1.2)) {
            $add(self::WARNING, 'Little free space in the storage directory: ' . SpaceCheck::format($free) . ' left, the last backup was ' . SpaceCheck::format($last->sidecar->size()) . '.');
        }

        return [
            'profile' => $profile,
            'backups' => count(array_filter($entries, static fn (BackupEntry $e): bool => $e->sidecar !== null && $e->condition === BackupEntry::COMPLETE)),
            'bytes' => $bytes,
            'last' => $last,
            'lastGood' => $lastGood,
            'run' => $state['run'] ?? null,
            'runState' => $state['state'] ?? null,
            'warnings' => $warnings,
            'level' => $level,
            'free' => $free,
        ];
    }

    private function free(string $directory): ?int
    {
        $existing = $directory;
        while (!file_exists($existing) && dirname($existing) !== $existing) {
            $existing = dirname($existing);
        }
        if ($this->freeSpace !== null) {
            return ($this->freeSpace)($existing);
        }
        $free = is_dir($existing) ? @disk_free_space($existing) : false;

        return $free === false ? null : (int) $free;
    }

    private static function reason(Run $run): string
    {
        $error = $run->error ?? 'unknown reason';

        return $run->status === RunStatus::Failed && preg_match('/^[A-Za-z\\\\]+: (.*)$/s', $error, $m) === 1 ? $m[1] : $error;
    }
}
